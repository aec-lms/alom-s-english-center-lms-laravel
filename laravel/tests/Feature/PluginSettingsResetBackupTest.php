<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class PluginSettingsResetBackupTest extends TestCase
{

    public function test_reset_keeps_existing_settings_when_recovery_directory_preparation_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-sslcommerz']);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-sslcommerz', [
            'store_id' => 'AEC-STORE-RECOVERY-001',
            'store_password' => 'recovery-password-secret',
            'sandbox_mode' => '0',
        ], ['store_id', 'store_password']);

        File::partialMock()->shouldReceive('ensureDirectoryExists')->once()->andThrow(new \RuntimeException('simulated recovery directory failure'));

        try {
            $manager->resetSettingsToDefaults('gateway-sslcommerz');
            $this->fail('Settings reset must stop when the recovery backup cannot be written.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('simulated recovery directory failure', $exception->getMessage());
        }

        $this->assertSame('AEC-STORE-RECOVERY-001', $manager->getSetting('gateway-sslcommerz', 'store_id'));
        $this->assertSame('recovery-password-secret', $manager->getSetting('gateway-sslcommerz', 'store_password'));
        $this->assertSame('0', $manager->getSetting('gateway-sslcommerz', 'sandbox_mode'));
    }

    public function test_exclusive_recovery_writer_refuses_symlink_destination_without_touching_target(): void
    {
        $manager = app(PluginManager::class);
        $directory = sys_get_temp_dir() . '/aec-plugin-recovery-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $target = $directory . '/outside.txt';
        $link = $directory . '/recovery.json';
        file_put_contents($target, 'must remain unchanged');
        $this->assertTrue(symlink($target, $link));

        try {
            $writer = new ReflectionMethod(PluginManager::class, 'writePrivateFileExclusive');
            try {
                $writer->invoke($manager, $link, '{"secret":"must-not-write"}');
                $this->fail('Exclusive recovery writer must refuse a pre-existing symlink.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('could not be created exclusively', $exception->getMessage());
            }

            $this->assertSame('must remain unchanged', file_get_contents($target));
        } finally {
            @unlink($link);
            @unlink($target);
            @rmdir($directory);
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
