<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Core\Security\AuditLogger;
use App\Models\BackupRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        if (is_link($backupDir)) {
            throw new RuntimeException('Backup storage directory must not be a symbolic link.');
        }
        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Unable to create private backup storage directory.');
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
            if (is_link($privateDir)) {
                $zip->close();
                @unlink($archivePath);
                throw new RuntimeException('Private storage directory must not be a symbolic link.');
            }
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
        $stagedPrivateFiles = $this->stagePrivateFiles($zip, $manifest);

        try {
            Schema::disableForeignKeyConstraints();
            try {
                DB::transaction(function () use ($dbSnapshot): void {
                    foreach ((array) ($dbSnapshot['tables'] ?? []) as $table => $rows) {
                        if (!is_string($table) || !Schema::hasTable($table) || $table === 'migrations') {
                            continue;
                        }
                        DB::table($table)->delete();
                        foreach (array_chunk((array) $rows, DB::connection()->getDriverName() === 'sqlite' ? 50 : 200) as $chunk) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                });
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            if ($stagedPrivateFiles !== null) {
                // Restore files by merging the snapshot; unrelated files are never deleted.
                $this->restoreStagedPrivateFiles($stagedPrivateFiles);
            }
        } finally {
            $zip->close();
            @unlink($tempZip);
            if ($stagedPrivateFiles !== null) {
                $this->deleteDirectoryTree($stagedPrivateFiles);
            }
        }

        $this->audit->log('backup.restored', 'backup', (string) $backup->id, ['filename' => $backup->filename], $actorId);
    }



    /**
     * Extract private files into an isolated staging directory after validating every ZIP entry.
     */
    private function stagePrivateFiles(ZipArchive $zip, array $manifest): ?string
    {
        $includesFiles = (bool) ($manifest['includes_files'] ?? false);
        $stagingDir = storage_path('app/restore-staging/' . Str::uuid()->toString());
        $seen = [];
        $totalBytes = 0;
        $fileCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === 'database.json' || $name === 'manifest.json') {
                continue;
            }
            if (!str_starts_with($name, 'private-files/')) {
                throw new RuntimeException("Unexpected entry in backup archive: {$name}");
            }
            if (!$includesFiles) {
                throw new RuntimeException('Backup archive contains private files but its manifest says files were excluded.');
            }

            $relative = substr($name, strlen('private-files/'));
            $normalized = str_replace('\\', '/', $relative);
            $segments = explode('/', $normalized);
            if ($relative === ''
                || str_contains($relative, "\0")
                || str_contains($relative, '\\')
                || str_starts_with($normalized, '/')
                || preg_match('/^[a-zA-Z]:/', $normalized) === 1
                || in_array('..', $segments, true)
                || in_array('.', $segments, true)
            ) {
                throw new RuntimeException("Unsafe private file path in backup: {$name}");
            }

            if (isset($seen[$normalized])) {
                throw new RuntimeException("Duplicate private file in backup: {$name}");
            }
            $seen[$normalized] = true;

            $stat = $zip->statIndex($i);
            $attributes = (int) ($stat['external_attributes'] ?? 0);
            if ((($attributes >> 16) & 0xF000) === 0xA000) {
                throw new RuntimeException("Symlink entry rejected in backup: {$name}");
            }

            $size = (int) ($stat['size'] ?? 0);
            $totalBytes += $size;
            if (++$fileCount > 5000 || $size > 268435456 || $totalBytes > 1073741824) {
                throw new RuntimeException('Private files in backup exceed the allowed size or file count.');
            }

            if (str_ends_with($normalized, '/')) {
                continue;
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false || strlen($contents) !== $size) {
                throw new RuntimeException("Unable to read private file from backup: {$name}");
            }

            $destination = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to create private restore staging directory.');
            }
            if (file_put_contents($destination, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Unable to stage private file from backup: {$name}");
            }
            @chmod($destination, 0600);
        }

        if (!$includesFiles) {
            return null;
        }

        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0700, true) && !is_dir($stagingDir)) {
            throw new RuntimeException('Unable to create private restore staging directory.');
        }

        return $stagingDir;
    }

    /**
     * Merge staged private files into the private storage root without following symlinks.
     */
    private function restoreStagedPrivateFiles(string $stagingDir): void
    {
        $privateDir = (string) config('lms.private_storage_path');
        if ($privateDir === '' || is_link($privateDir)) {
            throw new RuntimeException('Private storage path is invalid or is a symbolic link.');
        }
        if (!is_dir($privateDir) && !mkdir($privateDir, 0700, true) && !is_dir($privateDir)) {
            throw new RuntimeException('Unable to create private storage directory.');
        }

        $realPrivateDir = realpath($privateDir);
        if ($realPrivateDir === false) {
            throw new RuntimeException('Unable to resolve private storage directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace($stagingDir, '', $item->getPathname()), DIRECTORY_SEPARATOR);
            $segments = explode(DIRECTORY_SEPARATOR, $relative);
            $current = $realPrivateDir;
            foreach ($segments as $index => $segment) {
                $current .= DIRECTORY_SEPARATOR . $segment;
                if (is_link($current)) {
                    throw new RuntimeException('Refusing to restore through a symbolic link in private storage.');
                }
                if ($index < count($segments) - 1 && file_exists($current) && !is_dir($current)) {
                    throw new RuntimeException('Private restore path conflicts with an existing file.');
                }
            }

            if ($item->isDir()) {
                if (!is_dir($current) && !mkdir($current, 0700, true) && !is_dir($current)) {
                    throw new RuntimeException('Unable to create private storage subdirectory.');
                }
                continue;
            }

            $parent = dirname($current);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to create private storage directory.');
            }

            $temporary = tempnam($parent, '.aec-restore-');
            if ($temporary === false) {
                throw new RuntimeException('Unable to allocate temporary private restore file.');
            }
            try {
                if (!copy($item->getPathname(), $temporary) || !rename($temporary, $current)) {
                    throw new RuntimeException('Unable to atomically restore private file.');
                }
                @chmod($current, 0600);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
    }

    private function deleteDirectoryTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
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
            if ($file->isFile() && !$file->isLink()) {
                $relative = ltrim(str_replace($sourceDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $zip->addFile($file->getPathname(), $zipPrefix . '/' . str_replace('\\', '/', $relative));
            }
        }
    }
}
