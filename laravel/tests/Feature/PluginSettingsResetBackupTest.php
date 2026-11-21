<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class PluginSettingsResetBackupTest extends TestCase
{
    public function test_plugin_settings_and_audit_roll_back_together_when_audit_storage_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-sslcommerz', [
            'store_id' => 'ORIGINAL-STORE',
        ], ['store_id']);

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced plugin settings audit failure');
        });

        try {
            try {
                $manager->saveSettings('gateway-sslcommerz', [
                    'store_id' => 'CHANGED-STORE',
                ], ['store_id']);
                $this->fail('Plugin settings must not change without required audit evidence.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced plugin settings audit failure', $exception->getMessage());
            }

            $this->assertSame('ORIGINAL-STORE', $manager->getSetting('gateway-sslcommerz', 'store_id'));
        } finally {
            AuditLog::flushEventListeners();
        }
    }



    public function test_recycle_delete_audit_failure_restores_package_and_install_state(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'plugin.deleted_to_recycle_bin') {
                throw new RuntimeException('forced recycle delete audit failure');
            }
        });

        try {
            try {
                $manager->deleteToRecycleBin('gateway-sslcommerz');
                $this->fail('Recycle delete must fail when its required audit event cannot be persisted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced recycle delete audit failure', $exception->getMessage());
            }

            $this->assertFileExists(
                config('plugins.modules_path') . DIRECTORY_SEPARATOR . 'gateway-sslcommerz' . DIRECTORY_SEPARATOR . 'plugin.json'
            );
            $record = \\App\\Models\\PluginRecord::query()->where('slug', 'gateway-sslcommerz')->first();
            $this->assertNotNull($record);
            $this->assertTrue((bool) $record->is_installed);
            $this->assertTrue((bool) $record->is_enabled);
            $this->assertSame([], $manager->recycledPlugins());
        } finally {
            AuditLog::flushEventListeners();
        }
    }



    public function test_permanent_delete_audit_failure_recreates_recycle_entry(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $deleted = $manager->deleteToRecycleBin('gateway-sslcommerz');
        $archive = $deleted['archive'];

        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'plugin.permanently_deleted') {
                throw new RuntimeException('forced permanent delete audit failure');
            }
        });

        try {
            try {
                $manager->permanentlyDeleteFromRecycleBin($archive);
                $this->fail('Permanent delete must fail when its required audit event cannot be persisted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced permanent delete audit failure', $exception->getMessage());
            }

            $recycled = collect($manager->recycledPlugins())->firstWhere('archive', $archive);
            $this->assertNotNull($recycled);
            $this->assertFileExists(
                storage_path('app/plugin-recycle-bin/' . $archive . '/plugin.json')
            );
        } finally {
            AuditLog::flushEventListeners();
        }
    }


    public function test_restore_audit_failure_keeps_recycle_bin_metadata_and_returns_package(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $deleted = $manager->deleteToRecycleBin('gateway-sslcommerz');
        $archive = $deleted['archive'];

        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'plugin.restored_from_recycle_bin') {
                throw new RuntimeException('forced restore audit failure');
            }
        });

        try {
            try {
                $manager->restoreFromRecycleBin($archive);
                $this->fail('Restore must fail when its required audit event cannot be persisted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced restore audit failure', $exception->getMessage());
            }

            $this->assertFileExists(
                config('plugins.modules_path') . DIRECTORY_SEPARATOR . 'gateway-sslcommerz' . DIRECTORY_SEPARATOR . 'plugin.json'
            );
            $this->assertSame(
                $archive,
                collect($manager->recycledPlugins())->firstWhere('archive', $archive)['archive'] ?? null
            );
        } finally {
            AuditLog::flushEventListeners();
        }
    }


    public function test_admin_reset_restores_manifest_defaults_and_retains_encrypted_private_backup(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-sslcommerz', [
            'store_id' => 'AEC-LIVE-STORE-001',
            'store_password' => 'private-live-password',
            'sandbox_mode' => '0',
        ], ['store_id', 'store_password']);

        $backupRoot = storage_path('app/plugin-setting-backups');
        File::ensureDirectoryExists($backupRoot);
        $before = collect(File::files($backupRoot))->map(fn ($file) => $file->getPathname())->all();

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/admin/plugins/gateway-sslcommerz/reset-settings')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('', $manager->getSetting('gateway-sslcommerz', 'store_id'));
        $this->assertSame('', $manager->getSetting('gateway-sslcommerz', 'store_password'));
        $this->assertSame('1', $manager->getSetting('gateway-sslcommerz', 'sandbox_mode'));

        $newFiles = collect(File::files($backupRoot))
            ->filter(fn ($file) => !in_array($file->getPathname(), $before, true))
            ->values();
        $this->assertCount(1, $newFiles);

        $backup = json_decode(File::get($newFiles->first()->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('gateway-sslcommerz', $backup['plugin_slug']);

        $secretRow = collect($backup['settings'])->firstWhere('setting_key', 'store_password');
        $this->assertNotNull($secretRow);
        $this->assertTrue((bool) $secretRow['is_encrypted']);
        $this->assertNotSame('private-live-password', $secretRow['setting_value']);
        $this->assertSame('private-live-password', Crypt::decryptString($secretRow['setting_value']));
    }
}
