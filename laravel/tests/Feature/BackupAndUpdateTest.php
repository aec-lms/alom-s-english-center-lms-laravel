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



    public function test_legacy_update_rollback_restore_preserves_update_and_audit_history(): void
    {
        $this->markSystemInstalled();
        $timestamp = now();

        $updateId = DB::table('update_logs')->insertGetId([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'from_version' => '2.0.0',
            'to_version' => '2.0.1',
            'package_checksum' => str_repeat('b', 64),
            'status' => 'applied',
            'backup_reference' => 'legacy-rollback-fixture',
            'notes' => 'legacy rollback history fixture',
            'executed_by' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $auditId = DB::table('audit_logs')->insertGetId([
            'user_id' => null,
            'action' => 'update.applied',
            'entity_type' => 'core',
            'entity_id' => 'aec-lms-core',
            'ip_address' => null,
            'metadata' => json_encode(['to' => '2.0.1'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $restore = new \ReflectionMethod(BackupService::class, 'restoreLegacyDatabaseSnapshot');
        $restore->setAccessible(true);
        $restore->invoke(app(BackupService::class), [
            'tables' => [
                'update_logs' => [],
                'audit_logs' => [],
            ],
        ], true);

        $this->assertDatabaseHas('update_logs', ['id' => $updateId, 'status' => 'applied']);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditId, 'action' => 'update.applied']);
    }

    public function test_update_rollback_restore_preserves_update_and_audit_history(): void
    {
        $this->markSystemInstalled();
        $backup = app(BackupService::class)->createEncryptedBackup(null, false);
        $timestamp = now();

        $targetDir = storage_path('framework/testing/update-rollback-' . bin2hex(random_bytes(5)));
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'payload.txt';
        $snapshotRoot = storage_path('app/update-snapshots');
        $snapshotDir = $snapshotRoot . DIRECTORY_SEPARATOR . 'rollback-test-' . bin2hex(random_bytes(5));
        mkdir($targetDir, 0700, true);
        mkdir($snapshotDir . DIRECTORY_SEPARATOR . 'files', 0700, true);
        file_put_contents($targetFile, 'new version');
        file_put_contents($snapshotDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'payload.txt', 'previous version');
        file_put_contents($snapshotDir . DIRECTORY_SEPARATOR . 'snapshot.json', json_encode([
            'target_dir' => $targetDir,
            'entries' => [['path' => 'payload.txt', 'existed' => true]],
        ], JSON_THROW_ON_ERROR));

        $updateId = DB::table('update_logs')->insertGetId([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'from_version' => '2.0.0',
            'to_version' => '2.0.1',
            'package_checksum' => str_repeat('a', 64),
            'status' => 'applied',
            'backup_reference' => $backup->filename,
            'notes' => json_encode(['snapshot_path' => $snapshotDir], JSON_THROW_ON_ERROR),
            'executed_by' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $auditId = DB::table('audit_logs')->insertGetId([
            'user_id' => null,
            'action' => 'update.applied',
            'entity_type' => 'core',
            'entity_id' => 'aec-lms-core',
            'ip_address' => null,
            'metadata' => json_encode(['to' => '2.0.1'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        try {
            app(UniversalUpdateService::class)->rollbackUpdate(
                \App\Models\UpdateLog::query()->findOrFail($updateId)
            );

            $this->assertDatabaseHas('update_logs', ['id' => $updateId, 'status' => 'rolled_back']);
            $this->assertDatabaseHas('audit_logs', ['id' => $auditId, 'action' => 'update.applied']);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'update.rolled_back',
                'entity_type' => 'core',
                'entity_id' => 'aec-lms-core',
            ]);
            $this->assertSame('previous version', file_get_contents($targetFile));
        } finally {
            @unlink($targetFile);
            \Illuminate\Support\Facades\File::deleteDirectory($targetDir);
            \Illuminate\Support\Facades\File::deleteDirectory($snapshotDir);
            @unlink($backup->storage_path);
            $backup->delete();
        }
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
        $stage = new \ReflectionMethod(BackupService::class, 'stagePrivateFiles');
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
        $stage = new \ReflectionMethod(BackupService::class, 'stagePrivateFiles');
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
                \Illuminate\Support\Facades\File::deleteDirectory($stagingPath);
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



    public function test_signed_update_rejects_unix_symlink_entries_using_zip_external_attributes(): void
    {
        $this->markSystemInstalled();
        config(['lms.plugin_signing_key' => 'test-plugin-signing-key']);

        $zipPath = tempnam(sys_get_temp_dir(), 'aec_update_symlink_');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'version' => '2.0.1',
        ], JSON_THROW_ON_ERROR)));
        $this->assertTrue($zip->addFromString('files/link.php', 'target.php'));
        $this->assertTrue($zip->setExternalAttributesName(
            'files/link.php',
            ZipArchive::OPSYS_UNIX,
            0120777 << 16
        ));
        $this->assertTrue($zip->close());

        $sha = hash_file('sha256', $zipPath);
        $signature = hash_hmac('sha256', $sha, 'test-plugin-signing-key');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Symlink entry rejected in update ZIP');
            app(UniversalUpdateService::class)->verifySignedPackage($zipPath, $sha, $signature);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_failed_update_recovery_preserves_failure_log_and_audit_history(): void
    {
        $this->markSystemInstalled();
        config(['lms.plugin_signing_key' => 'test-plugin-signing-key']);

        $zipPath = tempnam(sys_get_temp_dir(), 'aec_update_failure_');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'version' => '2.0.1',
        ], JSON_THROW_ON_ERROR)));
        $this->assertTrue($zip->addEmptyDir('files'));
        $this->assertTrue($zip->close());

        $sha = hash_file('sha256', $zipPath);
        $signature = hash_hmac('sha256', $sha, 'test-plugin-signing-key');
        $historyId = DB::table('audit_logs')->insertGetId([
            'user_id' => null,
            'action' => 'pre_update.audit_history_marker',
            'entity_type' => 'test',
            'entity_id' => 'failed-update-recovery',
            'ip_address' => null,
            'metadata' => json_encode(['preserve' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->with('down')
            ->once()
            ->andReturn(0);
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->with('migrate', ['--force' => true])
            ->once()
            ->andReturn(1);
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->with('up')
            ->once()
            ->andReturn(0);

        try {
            try {
                app(UniversalUpdateService::class)->applyVerifiedUpdate($zipPath, $sha, $signature);
                $this->fail('A migration failure must trigger recovery and retain a failure log.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Database migrations failed', $exception->getMessage());
            }

            $this->assertDatabaseHas('update_logs', [
                'target_type' => 'core',
                'target_slug' => 'aec-lms-core',
                'to_version' => '2.0.1',
                'status' => 'failed',
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'id' => $historyId,
                'action' => 'pre_update.audit_history_marker',
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'update.failed',
                'entity_type' => 'core',
                'entity_id' => 'aec-lms-core',
            ]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_update_package_is_pinned_and_reverified_before_extraction(): void
    {
        config(['lms.plugin_signing_key' => 'test-plugin-signing-key']);
        $packagePath = tempnam(sys_get_temp_dir(), 'aec_update_pinned_source_');
        $snapshotPath = sys_get_temp_dir() . '/aec_update_pinned_' . bin2hex(random_bytes(8)) . '/package.zip';
        $zip = new ZipArchive();

        $this->assertTrue($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'version' => '2.0.1',
        ], JSON_THROW_ON_ERROR)));
        $this->assertTrue($zip->addFromString('files/payload.php', '<?php // signed payload'));
        $this->assertTrue($zip->close());

        $sha = hash_file('sha256', $packagePath);
        $signature = hash_hmac('sha256', $sha, 'test-plugin-signing-key');
        $service = app(UniversalUpdateService::class);
        $pin = new \ReflectionMethod(UniversalUpdateService::class, 'createVerifiedPackageSnapshot');
        $pin->setAccessible(true);

        try {
            $manifest = $pin->invoke($service, $packagePath, $sha, $signature, $snapshotPath);
            $this->assertSame('aec-lms-core', $manifest['target_slug']);
            $this->assertSame($sha, hash_file('sha256', $snapshotPath));

            // Replacing the original upload after pinning must not change the
            // package bytes subsequently consumed by ZIP extraction.
            file_put_contents($packagePath, 'replaced after verification');
            $this->assertSame($sha, hash_file('sha256', $snapshotPath));
            $this->assertSame(
                $manifest,
                $service->verifySignedPackage($snapshotPath, $sha, $signature)
            );
        } finally {
            @unlink($packagePath);
            @unlink($snapshotPath);
            @rmdir(dirname($snapshotPath));
        }
    }

    public function test_update_staging_streams_large_zip_entries_to_disk(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'aec_update_stream_');
        $this->assertNotFalse($zipPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $payload = str_repeat('large-update-payload-', 180000);
        $this->assertTrue($zip->addFromString('files/large.bin', $payload));
        $this->assertTrue($zip->close());

        $stagingDir = storage_path('app/test-update-stage-' . bin2hex(random_bytes(6)));
        $extract = new \ReflectionMethod(UniversalUpdateService::class, 'extractToStaging');
        $extract->setAccessible(true);

        try {
            $extract->invoke(app(UniversalUpdateService::class), $zipPath, $stagingDir);
            $this->assertSame(hash('sha256', $payload), hash_file('sha256', $stagingDir . '/files/large.bin'));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($stagingDir);
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


    public function test_private_backup_restore_rejects_a_symlinked_staging_root_before_writing_files(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'aec_restore_root_link_');
        $outside = sys_get_temp_dir() . '/aec_restore_outside_' . bin2hex(random_bytes(5));
        $stagingRoot = storage_path('app/restore-staging');
        $this->assertNotFalse($zipPath);
        mkdir($outside, 0700, true);
        if (is_link($stagingRoot)) {
            unlink($stagingRoot);
        } elseif (is_dir($stagingRoot)) {
            \Illuminate\Support\Facades\File::deleteDirectory($stagingRoot);
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString('private-files/student-documents/should-not-escape.txt', 'sensitive'));
        $this->assertTrue($zip->close());
        $archive = new ZipArchive();
        $this->assertTrue($archive->open($zipPath) === true);
        $stage = new \ReflectionMethod(BackupService::class, 'stagePrivateFiles');
        $stage->setAccessible(true);

        try {
            $this->assertTrue(symlink($outside, $stagingRoot));
            try {
                $stage->invoke(app(BackupService::class), $archive, ['includes_files' => true]);
                $this->fail('A symlinked restore staging root must be rejected.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\RuntimeException $exception) {
                $this->assertSame('Private restore staging root must not be a symbolic link.', $exception->getMessage());
            }
            $this->assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        } finally {
            $archive->close();
            if (is_link($stagingRoot)) {
                unlink($stagingRoot);
            }
            @rmdir($outside);
            @unlink($zipPath);
        }
    }


    public function test_signed_update_staging_rejects_a_symlinked_private_root(): void
    {
        $outside = sys_get_temp_dir() . '/aec_update_staging_outside_' . bin2hex(random_bytes(5));
        $stagingRoot = storage_path('app/update-staging');
        mkdir($outside, 0700, true);
        if (is_link($stagingRoot)) {
            unlink($stagingRoot);
        } elseif (is_dir($stagingRoot)) {
            \Illuminate\Support\Facades\File::deleteDirectory($stagingRoot);
        }

        try {
            $this->assertTrue(symlink($outside, $stagingRoot));
            $method = new \ReflectionMethod(UniversalUpdateService::class, 'createPrivateStorageDirectory');
            $method->setAccessible(true);

            try {
                $method->invoke(app(UniversalUpdateService::class), 'update-staging');
                $this->fail('A symlinked update staging root must be rejected.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Private update storage root must not be a symbolic link.', $exception->getMessage());
            }
            $this->assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        } finally {
            if (is_link($stagingRoot)) {
                unlink($stagingRoot);
            }
            @rmdir($outside);
        }
    }

}
