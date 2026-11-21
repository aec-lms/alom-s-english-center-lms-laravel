<?php

declare(strict_types=1);

namespace App\Core\Updates;

use App\Core\Backup\BackupService;
use App\Core\Security\AuditLogger;
use App\Core\Versioning\VersionConstraint;
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

        if ($zip->numFiles > 5000) {
            $zip->close();
            throw new RuntimeException('Update archive contains too many entries.');
        }

        $totalUncompressedBytes = 0;
        $seenEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);
            $this->assertSafeZipEntryPath($entryName);
            if (isset($seenEntries[$entryName])) {
                $zip->close();
                throw new RuntimeException("Duplicate ZIP entry rejected: {$entryName}");
            }
            $seenEntries[$entryName] = true;

            $stat = $zip->statIndex($i);
            $entrySize = (int) ($stat['size'] ?? 0);
            $totalUncompressedBytes += $entrySize;
            if ($entrySize > 268435456 || $totalUncompressedBytes > 1073741824) {
                $zip->close();
                throw new RuntimeException('Update archive exceeds the allowed uncompressed size.');
            }

            $opsys = 0;
            $externalAttributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $externalAttributes)
                && $opsys === ZipArchive::OPSYS_UNIX
            ) {
                // statIndex() does not expose Unix external attributes.
                $fileType = ($externalAttributes >> 16) & 0170000;
                if ($fileType === 0120000) {
                    $zip->close();
                    throw new RuntimeException("Symlink entry rejected in update ZIP: {$entryName}");
                }
                if ($fileType !== 0 && $fileType !== 0100000 && $fileType !== 0040000) {
                    $zip->close();
                    throw new RuntimeException("Special file entry rejected in update ZIP: {$entryName}");
                }
            }
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
        $targetType = (string) ($manifest['target_type'] ?? '');
        $targetSlug = trim((string) ($manifest['target_slug'] ?? ''));
        $toVersion = trim((string) ($manifest['version'] ?? ''));
        $fromVersion = null;

        if (!in_array($targetType, ['plugin', 'core'], true)) {
            throw new RuntimeException('Update manifest target_type must be either plugin or core.');
        }
        if ($targetSlug === ''
            || preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $targetSlug) !== 1
            || preg_match('/^\\d+\\.\\d+\\.\\d+(?:-[0-9A-Za-z.-]+)?(?:\\+[0-9A-Za-z.-]+)?$/', $toVersion) !== 1
        ) {
            throw new RuntimeException('Update manifest must specify a valid target_slug and semantic version.');
        }

        if ($targetType === 'plugin') {
            $plugin = PluginRecord::query()
                ->where('slug', $targetSlug)
                ->where('is_installed', true)
                ->first();
            if (!$plugin) {
                throw new RuntimeException("Plugin update target '{$targetSlug}' is not installed.");
            }
            $fromVersion = $plugin->version;
            if (version_compare($toVersion, (string) $fromVersion, '<=')) {
                throw new RuntimeException('Plugin update version must be newer than the installed version.');
            }
            if (!$this->coreVersionMatches((string) ($manifest['core_compatibility'] ?? '^2.0'))) {
                throw new RuntimeException("Plugin update {$targetSlug} is incompatible with Core.");
            }
        } else {
            $coreSlug = (string) config('plugins.core_identifier', 'aec-lms-core');
            if ($targetSlug !== $coreSlug) {
                throw new RuntimeException("Core update target must be '{$coreSlug}'.");
            }
            $fromVersion = (string) config('lms.core_version', '2.0.0');
            if (version_compare($toVersion, $fromVersion, '<=')) {
                throw new RuntimeException('Core update version must be newer than the installed version.');
            }
        }

        $stagingDir = $this->createPrivateStorageDirectory('update-staging');
        $packageSnapshotPath = $stagingDir . '.signed.zip';
        $snapshotDir = $this->createPrivateStorageDirectory(
            'update-snapshots',
            Str::slug($targetSlug) . '-' . Str::uuid()->toString()
        );

        // Pin and re-verify before taking a database backup or entering maintenance
        // mode. A replaced upload must fail without restoring an old DB snapshot.
        $pinnedManifest = $this->createVerifiedPackageSnapshot(
            $packagePath,
            $expectedSha256,
            $signatureHex,
            $packageSnapshotPath
        );
        if ($pinnedManifest !== $manifest) {
            @unlink($packageSnapshotPath);
            throw new RuntimeException('Update package changed between initial verification and staging.');
        }

        try {
            $backup = $this->backups->createEncryptedBackup($actorId, false);
            Artisan::call('down');
        } catch (\Throwable $exception) {
            if (is_file($packageSnapshotPath) && !is_link($packageSnapshotPath)) {
                @unlink($packageSnapshotPath);
            }
            throw $exception;
        }

        try {
            $this->extractToStaging($packageSnapshotPath, $stagingDir);

            $targetDir = $this->resolveTargetDirectory($targetType, $targetSlug);

            // Determine payload root inside staging ('files/' subfolder if present, otherwise root excluding manifest).
            $payloadRoot = is_dir($stagingDir . DIRECTORY_SEPARATOR . 'files')
                ? $stagingDir . DIRECTORY_SEPARATOR . 'files'
                : $stagingDir;

            // Snapshot only files that this update will overwrite/create. Never recursively copy
            // base_path() into storage/app, which is itself inside base_path().
            $this->snapshotPayloadFiles($payloadRoot, (string) $targetDir, $snapshotDir);

            if ($targetDir !== null) {
                $this->deployStagedFiles($payloadRoot, $targetDir);
            }

            if ($targetType === 'plugin') {
                PluginRecord::query()->where('slug', $targetSlug)->update(['version' => $toVersion]);
            }

            if (is_dir($payloadRoot)) {
                $migrationExitCode = Artisan::call('migrate', ['--force' => true]);
                if ($migrationExitCode !== 0) {
                    throw new RuntimeException('Database migrations failed (exit code ' . $migrationExitCode . ').');
                }
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
                'notes' => json_encode([
                    'message' => 'Signed update staged, verified, migrated, and applied with pre-update backup.',
                    'snapshot_path' => $snapshotDir,
                ], JSON_THROW_ON_ERROR),
                'executed_by' => $actorId,
            ]);
            $this->audit->log('update.applied', $targetType, $targetSlug, ['to' => $toVersion], $actorId);

            return $log;
        } catch (\Throwable $e) {
            $recoveryErrors = [];
            try {
                // Migrations may already have changed data. Restore the pre-update DB snapshot.
                $this->backups->restoreEncryptedBackup($backup, $actorId, true);
            } catch (\Throwable $recoveryError) {
                $recoveryErrors[] = 'Database recovery failed: ' . $recoveryError->getMessage();
            }
            try {
                if (is_file($snapshotDir . DIRECTORY_SEPARATOR . 'snapshot.json')) {
                    $this->restorePayloadSnapshot($snapshotDir);
                }
            } catch (\Throwable $recoveryError) {
                $recoveryErrors[] = 'Code recovery failed: ' . $recoveryError->getMessage();
            }

            $failureMessage = 'Update failed: ' . $e->getMessage();
            if ($recoveryErrors !== []) {
                $failureMessage .= ' | ' . implode(' | ', $recoveryErrors);
            }
            UpdateLog::query()->create([
                'target_type' => $targetType,
                'target_slug' => $targetSlug,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_checksum' => $expectedSha256,
                'status' => 'failed',
                'backup_reference' => $backup->filename,
                'notes' => json_encode([
                    'message' => $failureMessage,
                    'snapshot_path' => $snapshotDir,
                    'database_recovered' => !in_array('Database recovery failed:', array_map(
                        static fn (string $message): string => substr($message, 0, strlen('Database recovery failed:')),
                        $recoveryErrors
                    ), true),
                    'recovery_errors' => $recoveryErrors,
                ], JSON_THROW_ON_ERROR),
                'executed_by' => $actorId,
            ]);
            $this->audit->log('update.failed', $targetType, $targetSlug, ['error' => $failureMessage], $actorId);
            throw new RuntimeException($failureMessage, previous: $e);
        } finally {
            $this->deleteDirectory($stagingDir);
            if (is_file($packageSnapshotPath) && !is_link($packageSnapshotPath)) {
                @unlink($packageSnapshotPath);
            }
            Artisan::call('up');
        }
    }

    public function rollbackUpdate(UpdateLog $log, ?int $actorId = null): void
    {
        if (!in_array($log->status, ['applied'], true)) {
            throw new RuntimeException('Only an applied update can be rolled back.');
        }
        if (!$log->backup_reference) {
            throw new RuntimeException('No backup reference recorded for this update log.');
        }

        $backup = BackupRecord::query()->where('filename', $log->backup_reference)->first();
        if (!$backup) {
            throw new RuntimeException("Referenced backup archive {$log->backup_reference} was not found.");
        }

        $metadata = json_decode((string) $log->notes, true);
        $snapshotPath = is_array($metadata) ? (string) ($metadata['snapshot_path'] ?? '') : '';
        $snapshotRoot = realpath(storage_path('app/update-snapshots'));
        $resolvedSnapshot = $snapshotPath !== '' ? realpath($snapshotPath) : false;
        if ($snapshotRoot === false || $resolvedSnapshot === false
            || !str_starts_with($resolvedSnapshot, $snapshotRoot . DIRECTORY_SEPARATOR)
            || !is_file($resolvedSnapshot . DIRECTORY_SEPARATOR . 'snapshot.json')
        ) {
            throw new RuntimeException('Verified pre-update code snapshot is missing; rollback was stopped before changing the database.');
        }

        Artisan::call('down');
        try {
            // Preserve UpdateLog audit history while restoring application data.
            $this->backups->restoreEncryptedBackup($backup, $actorId, true);
            $this->restorePayloadSnapshot($resolvedSnapshot);
            Artisan::call('optimize:clear');
            $log->refresh();
            $log->update([
                'status' => 'rolled_back',
                'notes' => json_encode([
                    'message' => 'Rolled back code and database to pre-update snapshot.',
                    'snapshot_path' => $resolvedSnapshot,
                    'backup' => $backup->filename,
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->audit->log('update.rolled_back', $log->target_type, $log->target_slug, ['backup' => $backup->filename], $actorId);
        } finally {
            Artisan::call('up');
        }
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
        $parts = $this->assertSafeRemoteFeedUrl($url, false);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'lms_update_feed_url'],
            ['value' => trim($url), 'is_encrypted' => false, 'updated_by' => $actorId]
        );
        $this->audit->log('update.feed_configured', 'system_setting', 'lms_update_feed_url', ['host' => $parts['host']], $actorId);
    }

    public function checkRemoteFeed(): array
    {
        $url = $this->getRemoteFeedUrl();
        try {
            $this->assertSafeRemoteFeedUrl($url, true);
            $resp = Http::withOptions(['allow_redirects' => false])->timeout(6)->acceptJson()->get($url);
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


    /**
     * Restrict update-feed requests to public HTTPS hosts and prevent redirects to private networks.
     */
    private function assertSafeRemoteFeedUrl(string $url, bool $resolveDns): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new RuntimeException('Update feed must be a plain HTTPS URL on the standard port, without credentials, query strings or fragments.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || !str_contains($host, '.')
            || preg_match('/(?:^|\\.)(?:local|internal|test|example|invalid)$/', $host) === 1
        ) {
            throw new RuntimeException('Update feed host must be a public DNS hostname.');
        }

        if ($resolveDns) {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
            if ($addresses === []) {
                throw new RuntimeException('Update feed host did not resolve to a public IP address.');
            }
            foreach (array_unique($addresses) as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    throw new RuntimeException('Update feed host resolves to a private or reserved IP address.');
                }
            }
        }

        return ['host' => $host, 'path' => (string) ($parts['path'] ?? '/')];
    }

    private function assertSafeZipEntryPath(string $entryName): void
    {
        $normalized = str_replace('\\', '/', $entryName);
        $segments = explode('/', $normalized);
        if ($entryName === ''
            || str_contains($entryName, "\0")
            || str_contains($entryName, '\\')
            || str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:/', $normalized) === 1
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
        ) {
            throw new RuntimeException("Unsafe ZIP path detected: {$entryName}");
        }
    }

    /**
     * Copy an uploaded package into a private, exclusive file and verify that copy.
     * All subsequent ZIP reads must use this pinned artifact, never the mutable
     * caller-supplied path that was used for the initial signature check.
     *
     * @return array<string, mixed>
     */
    private function createVerifiedPackageSnapshot(
        string $packagePath,
        string $expectedSha256,
        string $signatureHex,
        string $snapshotPath,
    ): array {
        $directory = dirname($snapshotPath);
        if (is_link($directory)) {
            throw new RuntimeException('Update package staging directory must not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private update package staging directory.');
        }

        $input = @fopen($packagePath, 'rb');
        $output = @fopen($snapshotPath, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Unable to create a private snapshot of the signed update package.');
        }

        try {
            if (stream_copy_to_stream($input, $output) === false || !fflush($output)) {
                throw new RuntimeException('Unable to copy the signed update package into private staging.');
            }
            @chmod($snapshotPath, 0600);
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($snapshotPath);
            throw $exception;
        }

        fclose($input);
        fclose($output);

        try {
            return $this->verifySignedPackage($snapshotPath, $expectedSha256, $signatureHex);
        } catch (\Throwable $exception) {
            @unlink($snapshotPath);
            throw $exception;
        }
    }


    private function createPrivateStorageDirectory(string $rootName, ?string $childName = null): string
    {
        abort_unless(in_array($rootName, ['update-staging', 'update-snapshots'], true), 500,
            'Invalid private update storage directory.');
        $storagePath = storage_path('app');
        $storageRoot = realpath($storagePath);
        if ($storageRoot === false || is_link($storagePath)) {
            throw new RuntimeException('Private update storage root is unavailable or redirected.');
        }

        $root = $storageRoot . DIRECTORY_SEPARATOR . $rootName;
        if (is_link($root)) {
            throw new RuntimeException('Private update storage root must not be a symbolic link.');
        }
        if (!is_dir($root) && !@mkdir($root, 0700) && !is_dir($root)) {
            throw new RuntimeException('Unable to create private update storage root.');
        }
        if (!@chmod($root, 0700)) {
            throw new RuntimeException('Unable to restrict private update storage root permissions.');
        }

        $resolvedRoot = realpath($root);
        $rootStat = $resolvedRoot !== false ? @stat($resolvedRoot) : false;
        if ($resolvedRoot === false || dirname($resolvedRoot) !== $storageRoot || $rootStat === false
            || (($rootStat['mode'] & 0170000) !== 0040000) || (($rootStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Private update storage root must be a private real directory under storage/app.');
        }

        $childName = $childName ?? Str::uuid()->toString();
        if ($childName === '' || basename($childName) !== $childName || $childName === '.' || $childName === '..') {
            throw new RuntimeException('Invalid private update staging directory name.');
        }
        $child = $resolvedRoot . DIRECTORY_SEPARATOR . $childName;
        if (is_link($child) || !@mkdir($child, 0700)) {
            throw new RuntimeException('Unable to create isolated private update staging directory.');
        }
        $resolvedChild = realpath($child);
        $childStat = $resolvedChild !== false ? @stat($resolvedChild) : false;
        if ($resolvedChild === false || dirname($resolvedChild) !== $resolvedRoot || is_link($child)
            || $childStat === false || (($childStat['mode'] & 0170000) !== 0040000)
            || (($childStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Private update staging directory failed its filesystem safety checks.');
        }

        return $resolvedChild;
    }

    private function assertPrivateStorageChild(string $directory, string $rootName): string
    {
        abort_unless(in_array($rootName, ['update-staging', 'update-snapshots'], true), 500,
            'Invalid private update storage directory.');
        $storagePath = storage_path('app');
        $storageRoot = realpath($storagePath);
        if ($storageRoot === false || is_link($storagePath)) {
            throw new RuntimeException('Private update storage root is unavailable or redirected.');
        }
        $root = $storageRoot . DIRECTORY_SEPARATOR . $rootName;
        if (is_link($root)) {
            throw new RuntimeException('Private update storage root must not be a symbolic link.');
        }
        $resolvedRoot = realpath($root);
        $rootStat = $resolvedRoot !== false ? @stat($resolvedRoot) : false;
        if ($resolvedRoot === false || dirname($resolvedRoot) !== $storageRoot || $rootStat === false
            || (($rootStat['mode'] & 0170000) !== 0040000) || (($rootStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Private update storage root failed its filesystem safety checks.');
        }

        if (is_link($directory)) {
            throw new RuntimeException('Private update staging directory must not be a symbolic link.');
        }
        $resolvedDirectory = realpath($directory);
        $directoryStat = $resolvedDirectory !== false ? @stat($resolvedDirectory) : false;
        if ($resolvedDirectory === false || dirname($resolvedDirectory) !== $resolvedRoot
            || $directoryStat === false || (($directoryStat['mode'] & 0170000) !== 0040000)
            || (($directoryStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Private update staging directory failed its filesystem safety checks.');
        }

        return $resolvedDirectory;
    }

    private function extractToStaging(string $packagePath, string $stagingDir): void
    {
        $stagingDir = $this->assertPrivateStorageChild($stagingDir, 'update-staging');
        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Unable to open signed update ZIP for staging.');
        }

        $totalBytes = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $this->assertSafeZipEntryPath($entry);

                $opsys = 0;
                $externalAttributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $externalAttributes)
                    && $opsys === ZipArchive::OPSYS_UNIX
                ) {
                    $fileType = ($externalAttributes >> 16) & 0170000;
                    if ($fileType === 0120000
                        || ($fileType !== 0 && $fileType !== 0100000 && $fileType !== 0040000)) {
                        throw new RuntimeException("Unsafe special file entry in update ZIP: {$entry}");
                    }
                }

                $stat = $zip->statIndex($i);
                $size = (int) ($stat['size'] ?? 0);
                $totalBytes += $size;
                if ($size > 268435456 || $totalBytes > 1073741824) {
                    throw new RuntimeException('Update archive exceeds the allowed uncompressed size.');
                }

                $dest = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
                if (str_ends_with($entry, '/')) {
                    if (!is_dir($dest) && !mkdir($dest, 0700, true) && !is_dir($dest)) {
                        throw new RuntimeException("Unable to create update staging directory for {$entry}");
                    }
                    continue;
                }
                $parent = dirname($dest);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException("Unable to create update staging directory for {$entry}");
                }

                // Avoid materializing large ZIP members in PHP memory. Release
                // archives allow up to 256 MiB per entry, which exceeds many cPanel
                // memory_limit configurations.
                $input = $zip->getStream($entry);
                if (!is_resource($input)) {
                    throw new RuntimeException("Unable to open update archive entry: {$entry}");
                }
                $output = fopen($dest, 'xb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException("Unable to create staged update file: {$entry}");
                }
                try {
                    $copied = stream_copy_to_stream($input, $output, $size + 1);
                    if ($copied !== $size || !fflush($output)) {
                        throw new RuntimeException("Unable to stage update file completely: {$entry}");
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                @chmod($dest, 0600);
            }
        } finally {
            $zip->close();
        }
    }

    private function resolveTargetDirectory(string $targetType, string $targetSlug): ?string
    {
        if ($targetType === 'plugin') {
            $slug = Str::slug($targetSlug);
            return base_path('plugins/modules/' . $slug);
        }

        return base_path();
    }

    /**
     * Capture exactly the files an update will touch. The snapshot lives outside the
     * application tree so Core updates cannot recursively snapshot their own archive.
     */
    private function snapshotPayloadFiles(string $sourceDir, string $targetDir, string $snapshotDir): void
    {
        if (!is_dir($sourceDir) || $targetDir === '') {
            throw new RuntimeException('Update payload or target directory is unavailable.');
        }
        $snapshotDir = $this->assertPrivateStorageChild($snapshotDir, 'update-snapshots');

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = ltrim(substr($item->getPathname(), strlen($sourceDir)), DIRECTORY_SEPARATOR);
            if ($relative === 'update-manifest.json' || str_starts_with($relative, '.env')) {
                continue;
            }
            $destination = $targetDir . DIRECTORY_SEPARATOR . $relative;
            $existed = is_file($destination) && !is_link($destination);
            if (is_link($destination) || (file_exists($destination) && !$existed)) {
                throw new RuntimeException("Unsafe update destination rejected: {$relative}");
            }
            if ($existed) {
                $backupFile = $snapshotDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $relative;
                if (!is_dir(dirname($backupFile)) && !mkdir(dirname($backupFile), 0700, true) && !is_dir(dirname($backupFile))) {
                    throw new RuntimeException('Unable to create code snapshot directory.');
                }
                if (!copy($destination, $backupFile)) {
                    throw new RuntimeException("Unable to snapshot existing update file: {$relative}");
                }
            }
            $entries[] = ['path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative), 'existed' => $existed];
        }

        file_put_contents(
            $snapshotDir . DIRECTORY_SEPARATOR . 'snapshot.json',
            json_encode(['target_dir' => $targetDir, 'entries' => $entries], JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        @chmod($snapshotDir . DIRECTORY_SEPARATOR . 'snapshot.json', 0600);
    }

    private function restorePayloadSnapshot(string $snapshotDir): void
    {
        $snapshotDir = $this->assertPrivateStorageChild($snapshotDir, 'update-snapshots');
        $manifestPath = $snapshotDir . DIRECTORY_SEPARATOR . 'snapshot.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $targetDir = (string) ($manifest['target_dir'] ?? '');
        $allowedRoot = realpath(base_path());
        $resolvedTarget = realpath($targetDir);
        if ($allowedRoot === false || $resolvedTarget === false
            || ($resolvedTarget !== $allowedRoot && !str_starts_with($resolvedTarget, $allowedRoot . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('Code snapshot target directory is outside the application root.');
        }

        foreach ((array) ($manifest['entries'] ?? []) as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            $this->assertSafeZipEntryPath($relative);
            $destination = $resolvedTarget . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $backupFile = $snapshotDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!empty($entry['existed'])) {
                if (!is_file($backupFile) || is_link($backupFile)) {
                    throw new RuntimeException("Code snapshot file is missing: {$relative}");
                }
                if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
                    throw new RuntimeException('Unable to recreate directory during code rollback.');
                }
                if (!copy($backupFile, $destination)) {
                    throw new RuntimeException("Unable to restore code file: {$relative}");
                }
            } elseif (is_file($destination) && !is_link($destination)) {
                @unlink($destination);
            }
        }
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
        $storageRoot = realpath(storage_path('app'));
        $stagingRoot = $storageRoot !== false ? realpath($storageRoot . DIRECTORY_SEPARATOR . 'update-staging') : false;
        $resolvedDirectory = realpath($dir);
        if (!is_dir($dir) || is_link($dir) || $stagingRoot === false
            || $resolvedDirectory === false || dirname($resolvedDirectory) !== $stagingRoot) {
            return;
        }
        $dir = $resolvedDirectory;
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
        return VersionConstraint::matches(
            (string) config('lms.core_version', '2.0.0'),
            $constraint
        );
    }
}
