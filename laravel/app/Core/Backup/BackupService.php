<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Security\AuditLogger;
use App\Models\BackupRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

class BackupService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function createEncryptedBackup(?int $actorId = null, bool $includePrivateFiles = true): BackupRecord
    {
        $backupDir = (string) config('lms.backup_path');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $key = $this->resolveEncryptionKey();
        $timestamp = now()->format('Ymd-His');
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . "aec-backup-{$timestamp}-" . Str::lower(Str::random(8)) . '.zip';
        $encryptedPath = $archivePath . '.enc';

        $snapshot = $this->exportDatabaseSnapshot();

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create temporary backup archive.');
        }

        $zip->addFromString('database.json', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $zip->addFromString('manifest.json', json_encode([
            'core_version' => config('lms.core_version'),
            'created_at' => now()->toIso8601String(),
            'includes_files' => $includePrivateFiles,
            'tables' => array_keys($snapshot['tables']),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        if ($includePrivateFiles) {
            $privateDir = (string) config('lms.private_storage_path');
            if (is_dir($privateDir)) {
                $this->addDirectoryToZip($zip, $privateDir, 'private-files');
            }
        }
        $zip->close();

        $plaintext = file_get_contents($archivePath);
        @unlink($archivePath);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to read backup archive for encryption.');
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Backup encryption failed.');
        }
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        $temporaryEncryptedPath = tempnam($backupDir, '.aec-backup-');
        if ($temporaryEncryptedPath === false) {
            throw new RuntimeException('Unable to allocate temporary encrypted backup file.');
        }

        try {
            $written = file_put_contents($temporaryEncryptedPath, $hmac . $iv . $ciphertext, LOCK_EX);
            if ($written === false) {
                throw new RuntimeException('Unable to write encrypted backup file.');
            }
            @chmod($temporaryEncryptedPath, 0600);
            if (!rename($temporaryEncryptedPath, $encryptedPath)) {
                throw new RuntimeException('Unable to atomically publish encrypted backup file.');
            }
            @chmod($encryptedPath, 0600);
        } finally {
            if (is_file($temporaryEncryptedPath)) {
                @unlink($temporaryEncryptedPath);
            }
        }

        $checksum = hash_file('sha256', $encryptedPath);
        if ($checksum === false) {
            @unlink($encryptedPath);
            throw new RuntimeException('Unable to calculate encrypted backup checksum.');
        }
        $record = BackupRecord::query()->create([
            'filename' => basename($encryptedPath),
            'storage_path' => $encryptedPath,
            'checksum_sha256' => $checksum,
            'is_encrypted' => true,
            'includes_files' => $includePrivateFiles,
            'size_bytes' => filesize($encryptedPath) ?: 0,
            'core_version' => (string) config('lms.core_version', '2.0.0'),
            'metadata' => ['tables' => array_keys($snapshot['tables'])],
            'created_by' => $actorId,
        ]);

        $this->audit->log('backup.created', 'backup', (string) $record->id, ['filename' => $record->filename], $actorId);

        return $record;
    }

    public function verifyBackupIntegrity(BackupRecord $backup): bool
    {
        $backupPath = $this->resolveBackupPath($backup);
        if ($backupPath === null || !is_file($backupPath) || is_link($backupPath)) {
            return false;
        }

        $checksum = hash_file('sha256', $backupPath);
        return is_string($checksum)
            && preg_match('/^[a-f0-9]{64}$/i', (string) $backup->checksum_sha256) === 1
            && hash_equals(strtolower((string) $backup->checksum_sha256), strtolower($checksum));
    }

    public function restoreEncryptedBackup(BackupRecord $backup, ?int $actorId = null): void
    {
        if (!$this->verifyBackupIntegrity($backup)) {
            throw new RuntimeException('Backup integrity checksum verification failed.');
        }

        $key = $this->resolveEncryptionKey();
        $safeBackupPath = $this->resolveBackupPath($backup);
        $blob = $safeBackupPath === null ? false : file_get_contents($safeBackupPath);
        if ($blob === false || strlen($blob) < 49) {
            throw new RuntimeException('Corrupted or unreadable backup file.');
        }

        $hmac = substr($blob, 0, 32);
        $iv = substr($blob, 32, 16);
        $ciphertext = substr($blob, 48);
        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            throw new RuntimeException('Backup HMAC authentication failed.');
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Backup decryption failed.');
        }

        $tempZip = tempnam(sys_get_temp_dir(), 'aec_restore_');
        if ($tempZip === false) {
            throw new RuntimeException('Unable to allocate temporary restore archive.');
        }
        file_put_contents($tempZip, $plaintext);

        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            @unlink($tempZip);
            throw new RuntimeException('Invalid decrypted backup archive.');
        }

        $manifestRaw = $zip->getFromName('manifest.json');
        $dbRaw = $zip->getFromName('database.json');
        if ($manifestRaw === false || $dbRaw === false) {
            $zip->close();
            @unlink($tempZip);
            throw new RuntimeException('Backup archive missing manifest.json or database.json.');
        }

        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['core_version'] ?? '') !== (string) config('lms.core_version', '2.0.0')) {
            $zip->close();
            @unlink($tempZip);
            throw new RuntimeException('Backup core version mismatch.');
        }

        $dbSnapshot = json_decode($dbRaw, true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($dbSnapshot): void {
            foreach ((array) ($dbSnapshot['tables'] ?? []) as $table => $rows) {
                if (!is_string($table) || !Schema::hasTable($table) || $table === 'migrations') {
                    continue;
                }
                DB::table($table)->delete();
                foreach (array_chunk((array) $rows, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        $zip->close();
        @unlink($tempZip);
        $this->audit->log('backup.restored', 'backup', (string) $backup->id, ['filename' => $backup->filename], $actorId);
    }


    /**
     * Resolve backup files only from the configured private backup directory.
     * Database-controlled paths must never be allowed to read arbitrary server files.
     */
    private function resolveBackupPath(BackupRecord $backup): ?string
    {
        $backupDir = (string) config('lms.backup_path');
        if ($backupDir === '' || !is_dir($backupDir)) {
            return null;
        }

        $realBackupDir = realpath($backupDir);
        $filename = (string) $backup->filename;
        $storagePath = (string) $backup->storage_path;

        if ($realBackupDir === false
            || $filename === ''
            || basename($filename) !== $filename
            || !str_ends_with($filename, '.zip.enc')
            || $storagePath === ''
            || is_link($storagePath)
        ) {
            return null;
        }

        $realFile = realpath($storagePath);
        if ($realFile === false
            || dirname($realFile) !== $realBackupDir
            || basename($realFile) !== $filename
        ) {
            return null;
        }

        return $realFile;
    }

    private function resolveEncryptionKey(): string
    {
        $raw = (string) (config('lms.backup_encryption_key') ?: config('app.key'));
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false) {
                return hash('sha256', $decoded, true);
            }
        }
        return hash('sha256', $raw, true);
    }

    private function exportDatabaseSnapshot(): array
    {
        $tables = [];
        $driver = DB::connection()->getDriverName();
        $tableNames = $driver === 'sqlite'
            ? array_map(fn ($r) => $r->name, DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
            : array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW TABLES'));

        foreach ($tableNames as $table) {
            if ($table === 'backups') {
                continue;
            }
            $tables[$table] = array_map(fn ($row) => (array) $row, DB::table($table)->get()->all());
        }

        return ['exported_at' => now()->toIso8601String(), 'tables' => $tables];
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = ltrim(str_replace($sourceDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $zip->addFile($file->getPathname(), $zipPrefix . '/' . str_replace('\\', '/', $relative));
            }
        }
    }
}
