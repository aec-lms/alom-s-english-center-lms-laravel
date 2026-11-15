<?php

declare(strict_types=1);

namespace App\Core\Updates;

use App\Core\Backup\BackupService;
use App\Core\Security\AuditLogger;
use App\Models\BackupRecord;
use App\Models\PluginRecord;
use App\Models\SystemSetting;
use App\Models\UpdateLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class UniversalUpdateService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly AuditLogger $audit,
    ) {}

    public function verifySignedPackage(string $packagePath, string $expectedSha256, string $signatureHex): array
    {
        if (!is_file($packagePath)) {
            throw new RuntimeException('Update package file does not exist.');
        }

        $actualSha = hash_file('sha256', $packagePath) ?: '';
        if (!hash_equals(strtolower($expectedSha256), strtolower($actualSha))) {
            throw new RuntimeException('Update package SHA-256 checksum mismatch.');
        }

        $signingKey = (string) config('lms.plugin_signing_key');
        if ($signingKey === '') {
            throw new RuntimeException('AEC_PLUGIN_SIGNING_KEY is not configured; refusing unverified package.');
        }

        $expectedSignature = hash_hmac('sha256', $actualSha, $signingKey);
        if (!hash_equals($expectedSignature, strtolower($signatureHex))) {
            throw new RuntimeException('Update package digital signature is invalid.');
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Unable to open update archive.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);
            $this->assertSafeZipEntryPath($entryName);
        }

        $manifestRaw = $zip->getFromName('update-manifest.json');
        $zip->close();
        if ($manifestRaw === false) {
            throw new RuntimeException('Update package missing update-manifest.json.');
        }

        return json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function applyVerifiedUpdate(string $packagePath, string $expectedSha256, string $signatureHex, ?int $actorId = null): UpdateLog
    {
        $manifest = $this->verifySignedPackage($packagePath, $expectedSha256, $signatureHex);
        $targetType = (string) ($manifest['target_type'] ?? 'plugin');
        $targetSlug = trim((string) ($manifest['target_slug'] ?? ''));
        $toVersion = trim((string) ($manifest['version'] ?? ''));
        $fromVersion = null;

        if ($targetSlug === '' || $toVersion === '') {
            throw new RuntimeException('Update manifest must specify target_slug and version.');
        }

        if ($targetType === 'plugin') {
            $plugin = PluginRecord::query()->where('slug', $targetSlug)->first();
            $fromVersion = $plugin?->version;
            if (!$this->coreVersionMatches((string) ($manifest['core_compatibility'] ?? '^2.0'))) {
                throw new RuntimeException("Plugin update {$targetSlug} is incompatible with Core.");
            }
        } else {
            $fromVersion = (string) config('lms.core_version', '2.0.0');
        }

        // Create encrypted pre-update backup
        $backup = $this->backups->createEncryptedBackup($actorId, false);
        $stagingDir = storage_path('app/update-staging/' . Str::uuid()->toString());
        $snapshotDir = storage_path('app/update-snapshots/' . Str::slug($targetSlug) . '-' . now()->format('YmdHis'));

        Artisan::call('down');

        try {
            $this->extractToStaging($packagePath, $stagingDir);

            $targetDir = $this->resolveTargetDirectory($targetType, $targetSlug);
            if ($targetDir !== null && is_dir($targetDir)) {
                $this->copyDirectory($targetDir, $snapshotDir);
            }

            // Determine payload root inside staging ('files/' subfolder if present, otherwise root excluding manifest)
            $payloadRoot = is_dir($stagingDir . DIRECTORY_SEPARATOR . 'files')
                ? $stagingDir . DIRECTORY_SEPARATOR . 'files'
                : $stagingDir;

            if ($targetDir !== null) {
                $this->deployStagedFiles($payloadRoot, $targetDir);
            }

            if ($targetType === 'plugin') {
                PluginRecord::query()->where('slug', $targetSlug)->update(['version' => $toVersion]);
            }

            if (is_dir($payloadRoot)) {
                Artisan::call('migrate', ['--force' => true]);
            }
            Artisan::call('optimize:clear');

            $log = UpdateLog::query()->create([
                'target_type' => $targetType,
                'target_slug' => $targetSlug,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_checksum' => $expectedSha256,
                'status' => 'applied',
                'backup_reference' => $backup->filename,
                'notes' => 'Signed update staged, verified, migrated, and applied with pre-update backup.',
                'executed_by' => $actorId,
            ]);
            $this->audit->log('update.applied', $targetType, $targetSlug, ['to' => $toVersion], $actorId);

            return $log;
        } catch (\Throwable $e) {
            if (is_dir($snapshotDir)) {
                $targetDir = $this->resolveTargetDirectory($targetType, $targetSlug);
                if ($targetDir !== null) {
                    $this->copyDirectory($snapshotDir, $targetDir);
                }
            }
            $log = UpdateLog::query()->create([
                'target_type' => $targetType,
                'target_slug' => $targetSlug,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_checksum' => $expectedSha256,
                'status' => 'failed',
                'backup_reference' => $backup->filename,
                'notes' => 'Failed & auto-reverted files: ' . $e->getMessage(),
                'executed_by' => $actorId,
            ]);
            $this->audit->log('update.failed', $targetType, $targetSlug, ['error' => $e->getMessage()], $actorId);
            throw $e;
        } finally {
            $this->deleteDirectory($stagingDir);
            Artisan::call('up');
        }
    }

    public function rollbackUpdate(UpdateLog $log, ?int $actorId = null): void
    {
        if (!$log->backup_reference) {
            throw new RuntimeException('No backup reference recorded for this update log.');
        }

        $backup = BackupRecord::query()->where('filename', $log->backup_reference)->first();
        if (!$backup) {
            throw new RuntimeException("Referenced backup archive {$log->backup_reference} was not found.");
        }

        $this->backups->restoreEncryptedBackup($backup, $actorId);
        $log->update([
            'status' => 'rolled_back',
            'notes' => trim(($log->notes ?? '') . ' Rolled back to backup ' . $backup->filename),
        ]);
        Artisan::call('optimize:clear');
        $this->audit->log('update.rolled_back', $log->target_type, $log->target_slug, ['backup' => $backup->filename], $actorId);
    }

    public function getRemoteFeedUrl(): string
    {
        if (!Schema::hasTable('system_settings')) {
            return 'https://updates.alomsenglishcenter.com/lms-channel.json';
        }

        return (string) (SystemSetting::query()->where('key', 'lms_update_feed_url')->value('value')
            ?: 'https://updates.alomsenglishcenter.com/lms-channel.json');
    }

    public function saveRemoteFeedUrl(string $url, ?int $actorId = null): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'lms_update_feed_url'],
            ['value' => trim($url), 'is_encrypted' => false, 'updated_by' => $actorId]
        );
        $this->audit->log('update.feed_configured', 'system_setting', 'lms_update_feed_url', ['url' => $url], $actorId);
    }

    public function checkRemoteFeed(): array
    {
        $url = $this->getRemoteFeedUrl();
        try {
            $resp = Http::timeout(6)->acceptJson()->get($url);
            if ($resp->successful() && is_array($resp->json())) {
                return [
                    'reachable' => true,
                    'feed_url' => $url,
                    'payload' => $resp->json(),
                ];
            }

            return [
                'reachable' => false,
                'feed_url' => $url,
                'message' => 'Release feed returned HTTP ' . $resp->status() . '. Upload signed ZIP manually below.',
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'feed_url' => $url,
                'message' => 'Remote channel unreachable from this environment; upload a signed ZIP release package directly.',
            ];
        }
    }

    private function assertSafeZipEntryPath(string $entryName): void
    {
        if ($entryName === '' || str_contains($entryName, "\0") || str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
            throw new RuntimeException("Unsafe ZIP path detected: {$entryName}");
        }
    }

    private function extractToStaging(string $packagePath, string $stagingDir): void
    {
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }
        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Unable to open signed update ZIP for staging.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $this->assertSafeZipEntryPath($entry);
            $dest = $stagingDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry);
            if (str_ends_with($entry, '/')) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents !== false) {
                file_put_contents($dest, $contents);
            }
        }
        $zip->close();
    }

    private function resolveTargetDirectory(string $targetType, string $targetSlug): ?string
    {
        if ($targetType === 'plugin') {
            $slug = Str::slug($targetSlug);
            return base_path('plugins/modules/' . $slug);
        }

        return base_path();
    }

    private function deployStagedFiles(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($sourceDir)) {
            return;
        }
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $rel = ltrim(str_replace($sourceDir, '', $item->getPathname()), DIRECTORY_SEPARATOR);
            if ($rel === 'update-manifest.json' || str_starts_with($rel, '.env')) {
                continue;
            }
            $dest = $targetDir . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $rel = ltrim(str_replace($source, '', $item->getPathname()), DIRECTORY_SEPARATOR);
            $target = $dest . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function coreVersionMatches(string $constraint): bool
    {
        $coreMajor = explode('.', (string) config('lms.core_version', '2.0.0'))[0] ?? '2';
        $reqMajor = explode('.', ltrim($constraint, '^~>= '))[0] ?? $coreMajor;

        return $coreMajor === $reqMajor;
    }
}
