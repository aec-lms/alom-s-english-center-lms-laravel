<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginSettingsResetBackupTest extends TestCase
{
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
        $backupPath = $newFiles->first()->getPathname();
        clearstatcache(true, $backupRoot);
        clearstatcache(true, $backupPath);
        $this->assertSame(0, fileperms($backupRoot) & 0077, 'Plugin settings backup directory must be owner-only.');
        $this->assertSame(0, fileperms($backupPath) & 0077, 'Plugin settings backup file must be owner-only.');

        $backup = json_decode(File::get($backupPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('gateway-sslcommerz', $backup['plugin_slug']);

        $secretRow = collect($backup['settings'])->firstWhere('setting_key', 'store_password');
        $this->assertNotNull($secretRow);
        $this->assertTrue((bool) $secretRow['is_encrypted']);
        $this->assertNotSame('private-live-password', $secretRow['setting_value']);
        $this->assertSame('private-live-password', Crypt::decryptString($secretRow['setting_value']));
    }
    public function test_partial_recovery_backup_write_aborts_reset_and_preserves_existing_settings(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-sslcommerz', [
            'store_id' => 'AEC-KEEP-THIS-STORE',
            'store_password' => 'keep-this-secret',
            'sandbox_mode' => '0',
        ], ['store_id', 'store_password']);

        $manager = \\Mockery::mock(PluginManager::class, [app(\\App\\Core\\Security\\AuditLogger::class)])->makePartial();
        $manager->shouldAllowMockingProtectedMethods()
            ->shouldReceive('writeAllBytesToHandle')->once()->andReturn(1);

        try {
            $manager->resetSettingsToDefaults('gateway-sslcommerz');
            $this->fail('A partial recovery backup write must abort settings reset.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('backup write was incomplete', $exception->getMessage());
        }

        $this->assertSame('AEC-KEEP-THIS-STORE', $manager->getSetting('gateway-sslcommerz', 'store_id'));
        $this->assertSame('keep-this-secret', $manager->getSetting('gateway-sslcommerz', 'store_password'));
        $this->assertSame('0', $manager->getSetting('gateway-sslcommerz', 'sandbox_mode'));
    }
    public function test_false_recovery_backup_write_aborts_reset_and_preserves_existing_settings(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-sslcommerz', [
            'store_id' => 'AEC-KEEP-FALSE-WRITE',
            'store_password' => 'keep-false-write-secret',
            'sandbox_mode' => '0',
        ], ['store_id', 'store_password']);

        $manager = \\Mockery::mock(PluginManager::class, [app(\\App\\Core\\Security\\AuditLogger::class)])->makePartial();
        $manager->shouldAllowMockingProtectedMethods()
            ->shouldReceive('writeAllBytesToHandle')->once()->andReturn(0);

        try {
            $manager->resetSettingsToDefaults('gateway-sslcommerz');
            $this->fail('A false recovery backup write must abort settings reset.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('backup write was incomplete', $exception->getMessage());
        }

        $this->assertSame('AEC-KEEP-FALSE-WRITE', $manager->getSetting('gateway-sslcommerz', 'store_id'));
        $this->assertSame('keep-false-write-secret', $manager->getSetting('gateway-sslcommerz', 'store_password'));
        $this->assertSame('0', $manager->getSetting('gateway-sslcommerz', 'sandbox_mode'));
    }


    public function test_exclusive_backup_creation_rejects_existing_files_and_symlinks_without_touching_targets(): void
    {
        $this->markSystemInstalled();
        $root = storage_path('app/plugin-setting-exclusive-probe-' . bin2hex(random_bytes(5)));
        $target = storage_path('app/plugin-setting-exclusive-target-' . bin2hex(random_bytes(5)) . '.txt');
        File::ensureDirectoryExists($root);
        chmod($root, 0700);
        file_put_contents($target, 'must-remain-unchanged');
        $existingPath = $root . '/existing.json';
        file_put_contents($existingPath, 'existing-backup-must-remain-unchanged');
        $symlinkPath = $root . '/linked.json';

        try {
            $this->assertTrue(symlink($target, $symlinkPath));
            $manager = app(PluginManager::class);
            $writer = new \\ReflectionMethod(PluginManager::class, 'writeVerifiedPrivateJson');

            foreach (['existing.json', 'linked.json'] as $filename) {
                try {
                    $writer->invoke($manager, $root, $filename, ['replacement' => true]);
                    $this->fail('Exclusive backup creation must reject an existing destination.');
                } catch (\\RuntimeException $exception) {
                    $this->assertStringContainsString('created exclusively', $exception->getMessage());
                }
            }

            $this->assertSame('existing-backup-must-remain-unchanged', file_get_contents($existingPath));
            $this->assertSame('must-remain-unchanged', file_get_contents($target));
            $this->assertTrue(is_link($symlinkPath));
        } finally {
            @unlink($symlinkPath);
            @unlink($existingPath);
            @unlink($target);
            File::deleteDirectory($root);
        }
    }

}
