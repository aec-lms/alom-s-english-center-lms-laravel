<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Backup\BackupService;
use App\Core\Updates\UniversalUpdateService;
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
