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

        $backup = json_decode(File::get($newFiles->first()->getPathname()), true, 512, JSON_THROW_ON_ERROR);
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

        File::partialMock()->shouldReceive('put')->once()->andReturn(1);

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
}
