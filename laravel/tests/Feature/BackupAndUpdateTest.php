<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Backup\BackupService;
use App\Core\Updates\UniversalUpdateService;
use App\Models\BackupRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupAndUpdateTest extends TestCase
{
    public function test_restore_rehearsal_refuses_live_default_and_unconfigured_targets(): void
    {
        $this->markSystemInstalled();

        $record = BackupRecord::query()->create([
            'filename' => 'rehearsal-safety-fixture.zip.enc',
            'storage_path' => storage_path('app/backups/rehearsal-safety-fixture.zip.enc'),
            'checksum_sha256' => hash('sha256', 'not-a-real-backup'),
            'is_encrypted' => true,
            'includes_files' => false,
            'size_bytes' => 17,
            'core_version' => (string) config('lms.core_version', '2.0.0'),
            'metadata' => ['tables' => ['users']],
        ]);

        $this->artisan('lms:backup:rehearse', [
            'backup' => $record->id,
            '--connection' => (string) config('database.default'),
        ])->assertExitCode(1)
            ->expectsOutputToContain('different from the live default connection');

        config(['database.connections.missing_rehearsal' => null]);
        $this->artisan('lms:backup:rehearse', [
            'backup' => $record->id,
            '--connection' => 'missing_rehearsal',
        ])->assertExitCode(1)
            ->expectsOutputToContain('not fully configured');

        $record->delete();
    }

    public function test_encrypted_backup_creation_and_integrity_verification(): void
    {
        $this->markSystemInstalled();
        $backups = app(BackupService::class);

        $record = $backups->createEncryptedBackup(null, false);
        $this->assertTrue($record->is_encrypted);
        $this->assertFileExists($record->storage_path);
        $this->assertTrue($backups->verifyBackupIntegrity($record));

        // Tampering with encrypted archive invalidates integrity check
        file_put_contents($record->storage_path, 'tampered', FILE_APPEND);
        $this->assertFalse($backups->verifyBackupIntegrity($record));
        @unlink($record->storage_path);
    }



    public function test_production_size_database_backup_restores_with_bounded_streaming_batches(): void
    {
        $this->markSystemInstalled();

        $prefix = 'restore-load-' . bin2hex(random_bytes(4)) . '-';
        $timestamp = now();
        $passwordHash = password_hash('NotARealPassword!2026', PASSWORD_BCRYPT);
        $expected = 12000;
        for ($offset = 0; $offset < $expected; $offset += 50) {
            $rows = [];
            $limit = min($offset + 50, $expected);
            for ($i = $offset; $i < $limit; $i++) {
                $rows[] = [
                    'name' => 'Production Restore Student ' . $i,
                    'email' => $prefix . $i . '@aec.test',
                    'phone' => null,
                    'password' => $passwordHash,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
            DB::table('users')->insert($rows);
        }

        $backups = app(BackupService::class);
        $record = $backups->createEncryptedBackup(null, false);

        try {
            $this->assertTrue($backups->verifyBackupIntegrity($record));
            $this->assertSame(2, $record->metadata['backup_format'] ?? null);
            $this->assertStringStartsWith('AECBK02' . "\n", file_get_contents($record->storage_path, false, null, 0, 8));

            DB::table('users')->where('email', 'like', $prefix . '%')->delete();
            DB::table('users')->insert([
                'name' => 'Post-backup row',
                'email' => $prefix . 'post-backup@aec.test',
                'password' => password_hash('NotARealPassword!2026', PASSWORD_BCRYPT),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $backups->restoreEncryptedBackup($record);

            $this->assertSame($expected, DB::table('users')->where('email', 'like', $prefix . '%')->count());
            $this->assertSame('Production Restore Student 0', DB::table('users')->where('email', $prefix . '0@aec.test')->value('name'));
            $this->assertSame('Production Restore Student 11999', DB::table('users')->where('email', $prefix . '11999@aec.test')->value('name'));
            $this->assertFalse(DB::table('users')->where('email', $prefix . 'post-backup@aec.test')->exists());
        } finally {
            @unlink($record->storage_path);
            $record->delete();
        }
    }

    public function test_legacy_aes_encrypted_backup_restores_using_streamed_decryption(): void
    {
        $this->markSystemInstalled();

        $prefix = 'legacy-restore-' . bin2hex(random_bytes(4)) . '-';
        $timestamp = now();
        $passwordHash = password_hash('LegacyFixturePassword!2026', PASSWORD_BCRYPT);
        for ($offset = 0; $offset < 2000; $offset += 50) {
            $rows = [];
            for ($i = $offset; $i < $offset + 50; $i++) {
                $rows[] = [
                    'name' => 'Legacy Production Restore Student ' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'email' => $prefix . $i . '@aec.test',
                    'phone' => null,
                    'password' => $passwordHash,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
            DB::table('users')->insert($rows);
        }

        $snapshot = [
            'tables' => [
                'users' => DB::table('users')->get()->map(fn ($row) => (array) $row)->all(),
            ],
        ];
        $archivePath = tempnam(sys_get_temp_dir(), 'aec_legacy_fixture_');
        $zip = new ZipArchive();
        $this->assertSame(true, $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('manifest.json', json_encode([
            'core_version' => config('lms.core_version', '2.0.0'),
            'created_at' => now()->toIso8601String(),
            'includes_files' => false,
            'tables' => ['users'],
        ], JSON_THROW_ON_ERROR));
        $zip->addFromString('database.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $zip->setCompressionName('database.json', ZipArchive::CM_STORE);
        $zip->close();

        $rawKey = (string) (config('lms.backup_encryption_key') ?: config('app.key'));
        if (str_starts_with($rawKey, 'base64:')) {
            $decodedKey = base64_decode(substr($rawKey, 7), true);
            $this->assertNotFalse($decodedKey);
            $rawKey = $decodedKey;
        }
        $key = hash('sha256', $rawKey, true);
        $iv = random_bytes(16);
        $archiveBytes = file_get_contents($archivePath);
        $ciphertext = openssl_encrypt($archiveBytes, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $this->assertNotFalse($ciphertext);

        $backupDir = (string) config('lms.backup_path');
        if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);
        $legacyPath = $backupDir . '/legacy-' . bin2hex(random_bytes(8)) . '.zip.enc';
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        file_put_contents($legacyPath, $hmac . $iv . $ciphertext, LOCK_EX);

        $record = BackupRecord::query()->create([
            'filename' => basename($legacyPath),
            'storage_path' => $legacyPath,
            'checksum_sha256' => hash_file('sha256', $legacyPath),
            'is_encrypted' => true,
            'includes_files' => false,
            'size_bytes' => filesize($legacyPath),
            'core_version' => (string) config('lms.core_version', '2.0.0'),
            'metadata' => ['tables' => ['users'], 'backup_format' => 1],
        ]);

        try {
            $this->assertGreaterThan(65536, filesize($legacyPath));
            $this->assertFileExists($legacyPath);
            $this->assertSame(basename($legacyPath), $record->filename);
            $this->assertTrue(app(BackupService::class)->verifyBackupIntegrity($record));
            DB::table('users')->where('email', 'like', $prefix . '%')->delete();

            app(BackupService::class)->restoreEncryptedBackup($record);

            $this->assertSame(2000, DB::table('users')->where('email', 'like', $prefix . '%')->count());
            $this->assertSame(
                'Legacy Production Restore Student 00000',
                DB::table('users')->where('email', $prefix . '0@aec.test')->value('name')
            );
            $this->assertSame(
                'Legacy Production Restore Student 01999',
                DB::table('users')->where('email', $prefix . '1999@aec.test')->value('name')
            );
        } finally {
            @unlink($archivePath);
            @unlink($legacyPath);
            $record->delete();
        }
    }

    public function test_encrypted_backup_restores_included_private_files(): void
    {
        $this->markSystemInstalled();
        $privatePath = storage_path('app/test-private-' . uniqid('', true));
        config(['lms.private_storage_path' => $privatePath]);
        mkdir($privatePath, 0700, true);
        mkdir($privatePath . '/student-documents', 0700, true);
        file_put_contents($privatePath . '/student-documents/sample.txt', 'private student document');

        $backups = app(BackupService::class);
        $record = $backups->createEncryptedBackup(null, true);

        unlink($privatePath . '/student-documents/sample.txt');
        $backups->restoreEncryptedBackup($record);

        $this->assertSame(
            'private student document',
            file_get_contents($privatePath . '/student-documents/sample.txt')
        );

        @unlink($privatePath . '/student-documents/sample.txt');
        @rmdir($privatePath . '/student-documents');
        @rmdir($privatePath);
        @unlink($record->storage_path);
        $record->delete();
    }


    public function test_private_backup_restore_streams_large_entries_and_rejects_unix_symlinks(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'aec_private_restore_');
        $this->assertNotFalse($zipPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $largePayload = str_repeat('A', 4 * 1024 * 1024);
        $this->assertTrue($zip->addFromString('private-files/student-documents/large.bin', $largePayload));
        $this->assertTrue($zip->addFromString('private-files/student-documents/linked.txt', 'outside-target'));
        $this->assertTrue($zip->setExternalAttributesName(
            'private-files/student-documents/linked.txt',
            ZipArchive::OPSYS_UNIX,
            0120777 << 16
        ));
        $this->assertTrue($zip->close());

        $archive = new ZipArchive();
        $this->assertTrue($archive->open($zipPath) === true);
        $service = app(BackupService::class);
        $stage = new \\ReflectionMethod(BackupService::class, 'stagePrivateFiles');
        $stage->setAccessible(true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Symlink entry rejected in backup');
            $stage->invoke($service, $archive, ['includes_files' => true]);
        } finally {
            $archive->close();
            @unlink($zipPath);
        }
    }

    public function test_private_backup_restore_streams_large_file_entries_to_staging(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'aec_private_stream_');
        $this->assertNotFalse($zipPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $payload = str_repeat('streamed-private-content-', 120000);
        $this->assertTrue($zip->addFromString('private-files/student-documents/large.txt', $payload));
        $this->assertTrue($zip->close());

        $archive = new ZipArchive();
        $this->assertTrue($archive->open($zipPath) === true);
        $service = app(BackupService::class);
        $stage = new \\ReflectionMethod(BackupService::class, 'stagePrivateFiles');
        $stage->setAccessible(true);
        $stagingPath = null;

        try {
            $stagingPath = $stage->invoke($service, $archive, ['includes_files' => true]);
            $this->assertIsString($stagingPath);
            $this->assertSame(
                hash('sha256', $payload),
                hash_file('sha256', $stagingPath . '/student-documents/large.txt')
            );
        } finally {
            $archive->close();
            if (is_string($stagingPath) && is_dir($stagingPath)) {
                \\Illuminate\\Support\\Facades\\File::deleteDirectory($stagingPath);
            }
            @unlink($zipPath);
        }
    }

    public function test_backup_restore_rejects_paths_outside_the_private_backup_directory(): void
    {
        $this->markSystemInstalled();

        $outsidePath = sys_get_temp_dir() . '/aec-outside-' . uniqid('', true) . '.zip.enc';
        file_put_contents($outsidePath, 'not a backup');

        $record = new BackupRecord([
            'filename' => basename($outsidePath),
            'storage_path' => $outsidePath,
            'checksum_sha256' => hash_file('sha256', $outsidePath),
            'is_encrypted' => true,
        ]);

        $backups = app(BackupService::class);

        try {
            $this->assertFalse($backups->verifyBackupIntegrity($record));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup integrity checksum verification failed');
            $backups->restoreEncryptedBackup($record);
        } finally {
            @unlink($outsidePath);
        }
    }


    public function test_signed_update_rejects_windows_absolute_paths_in_zip_entries(): void
    {
        $this->markSystemInstalled();
        config(['lms.plugin_signing_key' => 'test-plugin-signing-key']);

        $zipPath = sys_get_temp_dir() . '/aec_update_path_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'version' => '2.0.1',
        ]));
        $zip->addFromString('C:/outside.php', '<?php // not allowed');
        $zip->close();

        $sha = hash_file('sha256', $zipPath);
        $signature = hash_hmac('sha256', $sha, 'test-plugin-signing-key');
        $updates = app(UniversalUpdateService::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe ZIP path detected');
            $updates->verifySignedPackage($zipPath, $sha, $signature);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_signed_update_rejects_unknown_target_types(): void
    {
        $this->markSystemInstalled();
        config(['lms.plugin_signing_key' => 'test-plugin-signing-key']);

        $zipPath = sys_get_temp_dir() . '/aec_update_target_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'arbitrary',
            'target_slug' => 'some-target',
            'version' => '2.0.1',
        ]));
        $zip->close();

        $sha = hash_file('sha256', $zipPath);
        $signature = hash_hmac('sha256', $sha, 'test-plugin-signing-key');
        $updates = app(UniversalUpdateService::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('target_type must be either plugin or core');
            $updates->applyVerifiedUpdate($zipPath, $sha, $signature);
        } finally {
            @unlink($zipPath);
        }
    }


    public function test_update_feed_rejects_insecure_and_private_hosts(): void
    {
        $this->markSystemInstalled();
        $updates = app(UniversalUpdateService::class);

        foreach ([
            'http://updates.example.com/feed.json',
            'https://127.0.0.1/feed.json',
            'https://localhost/feed.json',
            'https://updates.internal/feed.json',
            'https://updates.example.com/feed.json?token=secret',
        ] as $unsafeUrl) {
            try {
                $updates->saveRemoteFeedUrl($unsafeUrl);
                $this->fail("Unsafe update feed URL was accepted: {$unsafeUrl}");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Update feed', $exception->getMessage());
            }
        }
    }

    public function test_universal_update_rejects_invalid_hmac_signature(): void
    {
        $this->markSystemInstalled();
        $zipPath = sys_get_temp_dir() . '/aec_update_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'version' => '2.0.1',
        ]));
        $zip->close();

        $sha = hash_file('sha256', $zipPath);
        $updates = app(UniversalUpdateService::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('digital signature is invalid');
            $updates->verifySignedPackage($zipPath, $sha, str_repeat('0', 64));
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_backup_integrity_rejects_a_symlinked_backup_root(): void
    {
        $realDirectory = sys_get_temp_dir() . '/aec-backup-real-' . bin2hex(random_bytes(6));
        $linkedDirectory = sys_get_temp_dir() . '/aec-backup-link-' . bin2hex(random_bytes(6));
        mkdir($realDirectory, 0700, true);
        $filename = 'symlink-root-fixture.zip.enc';
        $realFile = $realDirectory . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($realFile, 'encrypted backup fixture');

        if (!@symlink($realDirectory, $linkedDirectory)) {
            @unlink($realFile);
            @rmdir($realDirectory);
            $this->markTestSkipped('The test environment does not permit creating symbolic links.');
        }

        config(['lms.backup_path' => $linkedDirectory]);
        $record = new BackupRecord([
            'filename' => $filename,
            'storage_path' => $linkedDirectory . DIRECTORY_SEPARATOR . $filename,
            'checksum_sha256' => hash_file('sha256', $realFile),
            'is_encrypted' => true,
        ]);

        try {
            $this->assertFalse(app(BackupService::class)->verifyBackupIntegrity($record));
        } finally {
            @unlink($linkedDirectory);
            @unlink($realFile);
            @rmdir($realDirectory);
        }
    }

}
