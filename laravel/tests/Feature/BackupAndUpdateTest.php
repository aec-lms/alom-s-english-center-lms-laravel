<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Backup\BackupService;
use App\Core\Updates\UniversalUpdateService;
use App\Models\BackupRecord;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupAndUpdateTest extends TestCase
{
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
}
