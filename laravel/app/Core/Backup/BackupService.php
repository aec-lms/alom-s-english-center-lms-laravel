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

        $jsonlPath = tempnam(sys_get_temp_dir(), 'aec_backup_db_');
        if ($jsonlPath === false) {
            throw new RuntimeException('Unable to allocate temporary database snapshot file.');
        }
        @chmod($jsonlPath, 0600);
        try {
            $tableNames = $this->exportDatabaseJsonLines($jsonlPath);
        } catch (\Throwable $exception) {
            @unlink($jsonlPath);
            throw $exception;
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($jsonlPath);
            throw new RuntimeException('Unable to create temporary backup archive.');
        }

        try {
            if (!$zip->addFile($jsonlPath, 'database.jsonl')) {
                throw new RuntimeException('Unable to add streamed database snapshot to backup archive.');
            }
            if (!$zip->addFromString('manifest.json', json_encode([
                'backup_format' => 2,
                'core_version' => config('lms.core_version'),
                'created_at' => now()->toIso8601String(),
                'includes_files' => $includePrivateFiles,
                'tables' => $tableNames,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
                throw new RuntimeException('Unable to add backup manifest to archive.');
            }

            if ($includePrivateFiles) {
                $privateDir = (string) config('lms.private_storage_path');
                if (is_link($privateDir)) {
                    throw new RuntimeException('Private storage directory must not be a symbolic link.');
                }
                if (is_dir($privateDir)) {
                    $this->addDirectoryToZip($zip, $privateDir, 'private-files');
                }
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($archivePath);
            @unlink($jsonlPath);
            throw $exception;
        }
        if (!$zip->close()) {
            @unlink($archivePath);
            @unlink($jsonlPath);
            throw new RuntimeException('Unable to finalize backup archive.');
        }
        @unlink($jsonlPath);

        try {
            $this->encryptArchiveStream($archivePath, $encryptedPath, $key);
        } finally {
            @unlink($archivePath);
        }

        $checksum = hash_file('sha256', $encryptedPath);
        if ($checksum === false) {
            @unlink($encryptedPath);
            throw new RuntimeException('Unable to calculate encrypted backup checksum.');
        }
        try {
            return DB::transaction(function () use ($encryptedPath, $checksum, $includePrivateFiles, $tableNames, $actorId): BackupRecord {
                $record = BackupRecord::query()->create([
                    'filename' => basename($encryptedPath),
                    'storage_path' => $encryptedPath,
                    'checksum_sha256' => $checksum,
                    'is_encrypted' => true,
                    'includes_files' => $includePrivateFiles,
                    'size_bytes' => filesize($encryptedPath) ?: 0,
                    'core_version' => (string) config('lms.core_version', '2.0.0'),
                    'metadata' => ['tables' => $tableNames, 'backup_format' => 2],
                    'created_by' => $actorId,
                ]);

                $this->audit->logRequired('backup.created', 'backup', (string) $record->id, ['filename' => $record->filename], $actorId);

                return $record;
            });
        } catch (\Throwable $exception) {
            @unlink($encryptedPath);
            throw $exception;
        }
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

    public function restoreEncryptedBackup(BackupRecord $backup, ?int $actorId = null, bool $preserveAuditHistory = false): void
    {
        if (!$this->verifyBackupIntegrity($backup)) {
            throw new RuntimeException('Backup integrity checksum verification failed.');
        }

        $key = $this->resolveEncryptionKey();
        $safeBackupPath = $this->resolveBackupPath($backup);
        if ($safeBackupPath === null) {
            throw new RuntimeException('Backup file path is invalid.');
        }

        $input = fopen($safeBackupPath, 'rb');
        if ($input === false) throw new RuntimeException('Unable to open encrypted backup file.');
        $magic = fread($input, 8);
        fclose($input);

        $tempZip = tempnam(sys_get_temp_dir(), 'aec_restore_');
        if ($tempZip === false) throw new RuntimeException('Unable to allocate temporary restore archive.');

        try {
            if ($magic === "AECBK02\n") {
                $this->decryptArchiveStream($safeBackupPath, $tempZip, $key);
            } else {
                // Legacy AES-CBC backups are authenticated in a first streaming pass,
                // then decrypted in bounded, block-aligned chunks.
                $this->decryptLegacyAesBackupStream($safeBackupPath, $tempZip, $key);
            }

            $zip = new ZipArchive();
            if ($zip->open($tempZip) !== true) throw new RuntimeException('Invalid decrypted backup archive.');

            $stagedPrivateFiles = null;
            try {
                $manifestRaw = $zip->getFromName('manifest.json');
                if ($manifestRaw === false) throw new RuntimeException('Backup archive missing manifest.json.');
                $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
                if (($manifest['core_version'] ?? '') !== (string) config('lms.core_version', '2.0.0')) {
                    throw new RuntimeException('Backup core version mismatch.');
                }

                $stagedPrivateFiles = $this->stagePrivateFiles($zip, $manifest);
                $jsonLinesStream = $zip->getStream('database.jsonl');
                if (is_resource($jsonLinesStream)) {
                    try {
                        $this->restoreDatabaseJsonLines($jsonLinesStream, $backup, $actorId, $magic === "AECBK02\n" ? 2 : 1, $preserveAuditHistory);
                    } finally {
                        fclose($jsonLinesStream);
                    }
                } else {
                    // Legacy JSON snapshots remain restorable; new backups use the streaming format.
                    $dbRaw = $zip->getFromName('database.json');
                    if ($dbRaw === false) throw new RuntimeException('Backup archive missing database.json or database.jsonl.');
                    $dbSnapshot = json_decode($dbRaw, true, 512, JSON_THROW_ON_ERROR);
                    $this->restoreLegacyDatabaseSnapshot($dbSnapshot, $backup, $actorId, $magic === "AECBK02\n" ? 2 : 1, $preserveAuditHistory);
                    unset($dbRaw, $dbSnapshot);
                }

                if ($stagedPrivateFiles !== null) $this->restoreStagedPrivateFiles($stagedPrivateFiles);
            } finally {
                $zip->close();
                if ($stagedPrivateFiles !== null) $this->deleteDirectoryTree($stagedPrivateFiles);
            }
        } finally {
            @unlink($tempZip);
        }

    }

    /**
     * Extract private files into an isolated staging directory after validating every ZIP entry.
     */
    private function stagePrivateFiles(ZipArchive $zip, array $manifest): ?string
    {
        $includesFiles = (bool) ($manifest['includes_files'] ?? false);
        $stagingDir = null;
        $seen = [];
        $totalBytes = 0;
        $fileCount = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (in_array($name, ['database.json', 'database.jsonl', 'manifest.json'], true)) {
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
                    || str_contains($relative, chr(0))
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

                $opsys = 0;
                $externalAttributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $externalAttributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $fileType = ($externalAttributes >> 16) & 0170000;
                    if ($fileType === 0120000) {
                        throw new RuntimeException("Symlink entry rejected in backup: {$name}");
                    }
                    if ($fileType !== 0 && !in_array($fileType, [0100000, 0040000], true)) {
                        throw new RuntimeException("Special file entry rejected in backup: {$name}");
                    }
                }

                $stat = $zip->statIndex($i);
                $size = (int) ($stat['size'] ?? 0);
                $totalBytes += $size;
                if (++$fileCount > 5000 || $size > 268435456 || $totalBytes > 1073741824) {
                    throw new RuntimeException('Private files in backup exceed the allowed size or file count.');
                }
                if (str_ends_with($normalized, '/')) {
                    continue;
                }

                $stagingDir ??= $this->createPrivateStorageChild('restore-staging');
                $destination = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException('Unable to create private restore staging directory.');
                }
                $input = $zip->getStream($name);
                if (!is_resource($input)) {
                    throw new RuntimeException("Unable to open private file from backup: {$name}");
                }
                $output = @fopen($destination, 'xb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException("Unable to create staged private file from backup: {$name}");
                }
                try {
                    $copied = stream_copy_to_stream($input, $output, $size + 1);
                    if ($copied !== $size || !fflush($output)) {
                        throw new RuntimeException("Unable to stage private file completely from backup: {$name}");
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                @chmod($destination, 0600);
            }

            if (!$includesFiles) {
                return null;
            }
            $stagingDir ??= $this->createPrivateStorageChild('restore-staging');
            return $stagingDir;
        } catch (\Throwable $exception) {
            if (is_string($stagingDir)) {
                $this->deleteDirectoryTree($stagingDir);
            }
            throw $exception;
        }
    }
    /**
     * Merge staged private files into the private storage root without following symlinks.
     */
    private function restoreStagedPrivateFiles(string $stagingDir): void
    {

        $storageRoot = realpath(storage_path('app'));
        $restoreRoot = $storageRoot !== false ? realpath($storageRoot . DIRECTORY_SEPARATOR . 'restore-staging') : false;
        $resolvedStaging = realpath($stagingDir);
        if ($storageRoot === false || $restoreRoot === false || is_link($stagingDir)
            || $resolvedStaging === false || dirname($resolvedStaging) !== $restoreRoot) {
            throw new RuntimeException('Private restore staging directory is outside its isolated storage root.');
        }
        $stagingDir = $resolvedStaging;
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

    private function createPrivateStorageChild(string $rootName): string
    {
        if ($rootName !== 'restore-staging') {
            throw new RuntimeException('Invalid private restore directory.');
        }
        $storagePath = storage_path('app');
        $storageRoot = realpath($storagePath);
        if ($storageRoot === false || is_link($storagePath)) {
            throw new RuntimeException('Private restore storage root is unavailable or redirected.');
        }
        $root = $storageRoot . DIRECTORY_SEPARATOR . $rootName;
        if (is_link($root)) {
            throw new RuntimeException('Private restore staging root must not be a symbolic link.');
        }
        if (!is_dir($root) && !@mkdir($root, 0700) && !is_dir($root)) {
            throw new RuntimeException('Unable to create private restore staging root.');
        }
        if (!@chmod($root, 0700)) {
            throw new RuntimeException('Unable to restrict private restore staging root permissions.');
        }
        $resolvedRoot = realpath($root);
        $rootStat = $resolvedRoot !== false ? @stat($resolvedRoot) : false;
        if ($resolvedRoot === false || dirname($resolvedRoot) !== $storageRoot || $rootStat === false
            || (($rootStat['mode'] & 0170000) !== 0040000) || (($rootStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Private restore staging root must be a private real directory under storage/app.');
        }
        $child = $resolvedRoot . DIRECTORY_SEPARATOR . Str::uuid()->toString();
        if (!@mkdir($child, 0700)) {
            throw new RuntimeException('Unable to create isolated private restore staging directory.');
        }
        $resolvedChild = realpath($child);
        $childStat = $resolvedChild !== false ? @stat($resolvedChild) : false;
        if ($resolvedChild === false || dirname($resolvedChild) !== $resolvedRoot || is_link($child)
            || $childStat === false || (($childStat['mode'] & 0170000) !== 0040000)
            || (($childStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Private restore staging directory failed its filesystem safety checks.');
        }
        return $resolvedChild;
    }

    private function deleteDirectoryTree(string $directory): void
    {
        $storageRoot = realpath(storage_path('app'));
        $restoreRoot = $storageRoot !== false ? realpath($storageRoot . DIRECTORY_SEPARATOR . 'restore-staging') : false;
        $resolvedDirectory = realpath($directory);
        if (!is_dir($directory) || is_link($directory) || $restoreRoot === false
            || $resolvedDirectory === false || dirname($resolvedDirectory) !== $restoreRoot) {
            return;
        }
        $directory = $resolvedDirectory;
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
        if ($backupDir === '' || is_link($backupDir) || !is_dir($backupDir)) {
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

    /**
     * Export rows incrementally as JSON Lines so database size does not determine PHP memory use.
     *
     * @return list<string>
     */
    private function exportDatabaseJsonLines(string $path): array
    {
        $stream = fopen($path, 'wb');
        if ($stream === false) throw new RuntimeException('Unable to create streamed database snapshot.');

        $driver = DB::connection()->getDriverName();
        $tableNames = $driver === 'sqlite'
            ? array_map(fn ($row) => $row->name, DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
            : array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));
        $tableNames = array_values(array_filter($tableNames, fn ($table) => !in_array($table, ['backups', 'migrations'], true)));

        try {
            $header = json_encode(['_format' => 'aec-jsonl-v2', 'tables' => $tableNames], JSON_THROW_ON_ERROR);
            if (fwrite($stream, $header . "\n") === false) throw new RuntimeException('Unable to write database snapshot header.');

            foreach ($tableNames as $table) {
                foreach (DB::table($table)->cursor() as $row) {
                    $line = json_encode(['table' => $table, 'row' => (array) $row], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    if (fwrite($stream, $line . "\n") === false) throw new RuntimeException("Unable to write database row for table {$table}.");
                }
            }
            fflush($stream);
        } catch (\Throwable $exception) {
            fclose($stream);
            @unlink($path);
            throw $exception;
        }
        fclose($stream);
        return $tableNames;
    }

    /** Stream-encrypt an archive with authenticated libsodium secretstream frames. */
    private function encryptArchiveStream(string $source, string $destination, string $key): void
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('The PHP sodium extension is required for production-size encrypted backups.');
        }
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Unable to open backup streams for encryption.');
        }
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($output, "AECBK02\n" . $header);
            $chunk = fread($input, 65536);
            if ($chunk === false) throw new RuntimeException('Unable to read temporary backup archive.');
            do {
                $next = fread($input, 65536);
                if ($next === false) throw new RuntimeException('Unable to read temporary backup archive.');
                $final = $next === '' && feof($input);
                $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                $frame = pack('N', strlen($cipher)) . $cipher;
                if (fwrite($output, $frame) !== strlen($frame)) throw new RuntimeException('Unable to write encrypted backup stream.');
                if ($final) break;
                $chunk = $next;
            } while (true);
            fflush($output);
        } finally {
            fclose($input);
            fclose($output);
        }
        @chmod($destination, 0600);
    }

    /** Authenticate and decrypt a v2 backup incrementally to a temporary ZIP file. */
    private function decryptArchiveStream(string $source, string $destination, string $key): void
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')) {
            throw new RuntimeException('The PHP sodium extension is required to restore this production-size backup.');
        }
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Unable to open backup streams for decryption.');
        }
        try {
            if ($this->readExact($input, 8) !== "AECBK02\n") throw new RuntimeException('Unsupported encrypted backup format.');
            $header = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finished = false;
            while (!feof($input)) {
                $lengthBytes = fread($input, 4);
                if ($lengthBytes === '' && feof($input)) break;
                if ($lengthBytes === false || strlen($lengthBytes) !== 4) throw new RuntimeException('Truncated encrypted backup frame.');
                $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Invalid encrypted backup frame length.');
                }
                $cipher = $this->readExact($input, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($pulled === false) throw new RuntimeException('Backup authentication failed during streaming decryption.');
                [$plain, $tag] = $pulled;
                if ($plain !== '' && fwrite($output, $plain) !== strlen($plain)) throw new RuntimeException('Unable to write decrypted backup archive.');
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finished = true;
                    if (fread($input, 1) !== '') throw new RuntimeException('Unexpected data after final encrypted backup frame.');
                    break;
                }
            }
            if (!$finished) throw new RuntimeException('Encrypted backup stream is incomplete.');
            fflush($output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /**
     * Authenticate and decrypt the legacy [HMAC][IV][AES-CBC] format without loading
     * the encrypted backup or plaintext ZIP into PHP memory.
     */
    private function decryptLegacyAesBackupStream(string $source, string $destination, string $key): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) throw new RuntimeException('Unable to open legacy encrypted backup.');

        $output = null;
        try {
            $fileSize = filesize($source);
            if ($fileSize === false || $fileSize < 64 || (($fileSize - 48) % 16) !== 0) {
                throw new RuntimeException('Corrupted or truncated legacy AES backup.');
            }

            $storedHmac = $this->readExact($input, 32);
            $iv = $this->readExact($input, 16);
            $hmac = hash_init('sha256', HASH_HMAC, $key);
            hash_update($hmac, $iv);

            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) throw new RuntimeException('Unable to read legacy backup for authentication.');
                if ($chunk === '') break;
                hash_update($hmac, $chunk);
            }
            if (!hash_equals($storedHmac, hash_final($hmac, true))) {
                throw new RuntimeException('Backup HMAC authentication failed.');
            }

            if (fseek($input, 48, SEEK_SET) !== 0) throw new RuntimeException('Unable to rewind legacy backup stream.');
            $output = fopen($destination, 'wb');
            if ($output === false) throw new RuntimeException('Unable to create decrypted legacy backup archive.');

            $previousCipherBlock = $iv;
            $remaining = $fileSize - 48;
            while ($remaining > 0) {
                $readLength = min(65536, $remaining);
                $readLength -= $readLength % 16;
                $cipherChunk = $this->readExact($input, $readLength);
                $plainChunk = openssl_decrypt(
                    $cipherChunk,
                    'AES-256-CBC',
                    $key,
                    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                    $previousCipherBlock
                );
                if ($plainChunk === false || strlen($plainChunk) !== strlen($cipherChunk)) {
                    throw new RuntimeException('Legacy AES backup decryption failed.');
                }

                $previousCipherBlock = substr($cipherChunk, -16);
                $remaining -= $readLength;

                if ($remaining === 0) {
                    $padding = ord($plainChunk[strlen($plainChunk) - 1]);
                    if ($padding < 1 || $padding > 16
                        || substr($plainChunk, -$padding) !== str_repeat(chr($padding), $padding)) {
                        throw new RuntimeException('Legacy AES backup padding validation failed.');
                    }
                    $plainChunk = substr($plainChunk, 0, -$padding);
                }

                if ($plainChunk !== '' && fwrite($output, $plainChunk) !== strlen($plainChunk)) {
                    throw new RuntimeException('Unable to write decrypted legacy backup archive.');
                }
            }
            fflush($output);
            @chmod($destination, 0600);
        } finally {
            fclose($input);
            if (is_resource($output)) fclose($output);
        }
    }

    /** Read exactly the requested number of bytes from a stream. */
    private function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $part = fread($stream, $length - strlen($data));
            if ($part === false || $part === '') break;
            $data .= $part;
        }
        if (strlen($data) !== $length) throw new RuntimeException('Unexpected end of encrypted backup stream.');
        return $data;
    }

    /**
     * Restore JSONL rows with bounded insert batches in a single database transaction.
     *
     * @param resource $stream
     */
    private function restoreDatabaseJsonLines($stream, BackupRecord $backup, ?int $actorId, int $backupFormat, bool $preserveAuditHistory = false): void
    {
        $headerLine = fgets($stream);
        if ($headerLine === false) throw new RuntimeException('Streamed database snapshot is empty.');
        $header = json_decode($headerLine, true, 512, JSON_THROW_ON_ERROR);
        if (($header['_format'] ?? null) !== 'aec-jsonl-v2' || !is_array($header['tables'] ?? null)) {
            throw new RuntimeException('Unsupported streamed database snapshot format.');
        }
        $tables = [];
        foreach ($header['tables'] as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1
                || in_array($table, ['migrations', 'backups'], true) || !Schema::hasTable($table)) {
                throw new RuntimeException('Backup contains an invalid or unavailable database table.');
            }
            $tables[] = $table;
        }

        $preservedTables = $preserveAuditHistory ? ['update_logs', 'audit_logs'] : [];
        $restoreTables = array_values(array_diff($tables, $preservedTables));

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($stream, $restoreTables, $preservedTables, $backup, $actorId, $backupFormat): void {
                foreach ($restoreTables as $table) DB::table($table)->delete();
                $currentTable = null;
                $batch = [];
                $batchSize = DB::connection()->getDriverName() === 'sqlite' ? 50 : 500;
                while (($line = fgets($stream)) !== false) {
                    if (trim($line) === '') continue;
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $table = $record['table'] ?? null;
                    $row = $record['row'] ?? null;
                    if (!is_string($table) || !is_array($row) || $row === []) {
                        throw new RuntimeException('Streamed database snapshot contains an invalid row.');
                    }
                    if (in_array($table, $preservedTables, true)) {
                        continue;
                    }
                    if (!in_array($table, $restoreTables, true)) {
                        throw new RuntimeException('Streamed database snapshot contains an invalid row.');
                    }
                    if ($currentTable !== null && $table !== $currentTable) {
                        if ($batch !== []) DB::table($currentTable)->insert($batch);
                        $batch = [];
                    }
                    $currentTable = $table;
                    $batch[] = $row;
                    if (count($batch) >= $batchSize) {
                        DB::table($currentTable)->insert($batch);
                        $batch = [];
                    }
                }
                if (!feof($stream)) throw new RuntimeException('Unable to read streamed database snapshot completely.');
                if ($currentTable !== null && $batch !== []) DB::table($currentTable)->insert($batch);

                $this->audit->logRequired('backup.restored', 'backup', (string) $backup->id, [
                    'filename' => $backup->filename,
                    'backup_format' => $backupFormat,
                ], $actorId);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** Restore original full-JSON archives created before the streaming format. */
    private function restoreLegacyDatabaseSnapshot(array $dbSnapshot, BackupRecord $backup, ?int $actorId, int $backupFormat, bool $preserveAuditHistory = false): void
    {
        $preservedTables = $preserveAuditHistory ? ['update_logs', 'audit_logs'] : [];
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($dbSnapshot, $backup, $actorId, $backupFormat, $preservedTables): void {
                foreach ((array) ($dbSnapshot['tables'] ?? []) as $table => $rows) {
                    if (!is_string($table) || !Schema::hasTable($table)
                        || in_array($table, ['migrations', 'backups'], true)
                        || in_array($table, $preservedTables, true)) continue;
                    DB::table($table)->delete();
                    foreach (array_chunk((array) $rows, DB::connection()->getDriverName() === 'sqlite' ? 50 : 200) as $chunk) {
                        if ($chunk !== []) DB::table($table)->insert($chunk);
                    }
                }

                $this->audit->logRequired('backup.restored', 'backup', (string) $backup->id, [
                    'filename' => $backup->filename,
                    'backup_format' => $backupFormat,
                ], $actorId);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $relative = ltrim(str_replace($sourceDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                if (!$zip->addFile($file->getPathname(), $zipPrefix . '/' . str_replace('\\', '/', $relative))) {
                    throw new RuntimeException('Unable to add private file to backup archive: ' . $relative);
                }
            }
        }
    }
}
