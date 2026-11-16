<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Core\Versioning\VersionConstraint;
use App\Models\PluginRecord;
use App\Models\PluginSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class PluginLifecycleTest extends TestCase
{

    public function test_plugin_core_compatibility_checks_minimum_semantic_versions(): void
    {
        $this->assertTrue(VersionConstraint::matches('2.0.0', '^2.0'));
        $this->assertTrue(VersionConstraint::matches('2.4.9', '^2.0'));
        $this->assertFalse(VersionConstraint::matches('2.0.0', '^2.1'));
        $this->assertFalse(VersionConstraint::matches('3.0.0', '^2.0'));
        $this->assertTrue(VersionConstraint::matches('2.1.9', '~2.1'));
        $this->assertFalse(VersionConstraint::matches('2.2.0', '~2.1.0'));
        $this->assertTrue(VersionConstraint::matches('2.1.0', '>=2.0'));
    }

    public function test_incompatible_installed_plugin_cannot_be_reenabled_after_core_upgrade(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        $manager->install('student-management');
        $manager->disable('student-management');

        config(['lms.core_version' => '3.0.0']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is incompatible with Core 3.0.0');
        $manager->enable('student-management');
    }

    public function test_student_plugin_installs_without_optional_branch_plugin(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $manifest = \App\Core\Plugins\PluginManifest::fromDirectory(base_path('plugins/modules/student-management'));
        $this->assertSame([], $manifest->dependencies);
        $this->assertSame(['branch-management'], $manifest->optionalDependencies);

        $manager->install('student-management');

        $this->assertTrue($manager->isInstalled('student-management'));
        $this->assertFalse($manager->isInstalled('branch-management'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('students'));
    }

    public function test_uninstalled_plugin_cannot_be_updated_through_the_lifecycle_manager(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be installed before it can be updated');
        $manager->updatePlugin('branch-management');
    }

    public function test_plugin_select_settings_reject_values_outside_manifest_options(): void
    {
        $this->markSystemInstalled();

        $request = \Illuminate\Http\Request::create('/admin/plugins/gateway-stripe/configure', 'POST', [
            'settings' => ['sandbox_mode' => 'production-ish'],
        ]);

        try {
            app(\App\Http\Controllers\Admin\PluginCenterController::class)
                ->saveConfiguration($request, 'gateway-stripe');
            $this->fail('Invalid select option should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('settings.sandbox_mode', $exception->errors());
        }

        $this->assertDatabaseMissing('plugin_settings', [
            'plugin_slug' => 'gateway-stripe',
            'setting_key' => 'sandbox_mode',
        ]);
    }

    public function test_legacy_encrypted_manifest_settings_are_encrypted_by_plugin_center(): void
    {
        $this->markSystemInstalled();

        $request = \Illuminate\Http\Request::create('/admin/plugins/email-notifications/configure', 'POST', [
            'settings' => [
                'smtp_host' => 'smtp.example.test',
                'smtp_password' => 'smtp-app-secret-123',
            ],
        ]);

        app(\App\Http\Controllers\Admin\PluginCenterController::class)
            ->saveConfiguration($request, 'email-notifications');

        $setting = PluginSetting::query()
            ->where('plugin_slug', 'email-notifications')
            ->where('setting_key', 'smtp_password')
            ->firstOrFail();

        $this->assertTrue($setting->is_encrypted);
        $this->assertNotSame('smtp-app-secret-123', $setting->setting_value);
        $this->assertSame('smtp-app-secret-123', app(PluginManager::class)->getSetting(
            'email-notifications',
            'smtp_password'
        ));
    }

    public function test_plugin_configuration_rejects_unknown_setting_keys(): void
    {
        $this->markSystemInstalled();

        $request = \Illuminate\Http\Request::create('/admin/plugins/gateway-stripe/configure', 'POST', [
            'settings' => [
                'sandbox_mode' => '1',
                'secret_key_override' => 'untrusted',
            ],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Http\Controllers\Admin\PluginCenterController::class)
            ->saveConfiguration($request, 'gateway-stripe');
    }

    public function test_enable_and_disable_hook_failures_roll_back_plugin_state(): void
    {
        $this->markSystemInstalled();

        $slug = 'lifecycle-failure-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir);

        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Lifecycle Failure Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
            'provider' => 'Plugins\\Modules\\LifecycleFailureProbe\\Providers\\LifecycleFailureProbeServiceProvider',
        ], JSON_THROW_ON_ERROR));

        $providerDir = $pluginDir . DIRECTORY_SEPARATOR . 'Providers';
        File::ensureDirectoryExists($providerDir);
        file_put_contents($providerDir . DIRECTORY_SEPARATOR . 'LifecycleFailureProbeServiceProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Plugins\Modules\LifecycleFailureProbe\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use App\Models\PluginRecord;
use RuntimeException;

class LifecycleFailureProbeServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'lifecycle-failure-probe';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function onEnable(): void
    {
        throw new RuntimeException('intentional enable hook failure');
    }

    public function onDisable(): void
    {
        throw new RuntimeException('intentional disable hook failure');
    }

    public function onUninstall(bool $purgePluginData = false): void
    {
        PluginRecord::query()->where('slug', $this->slug())->update(['is_installed' => false]);
        throw new RuntimeException('intentional uninstall hook failure');
    }
}
PHP);

        PluginRecord::query()->create([
            'slug' => $slug,
            'name' => 'Lifecycle Failure Probe',
            'version' => '1.0.0',
            'is_installed' => true,
            'is_enabled' => false,
            'dependencies' => [],
        ]);

        $manager = app(PluginManager::class);

        try {
            try {
                $manager->enable($slug);
                $this->fail('Enable hook failure should abort the operation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('intentional enable hook failure', $exception->getMessage());
            }

            $this->assertDatabaseHas('plugins', ['slug' => $slug, 'is_enabled' => false]);

            PluginRecord::query()->where('slug', $slug)->update(['is_enabled' => true]);

            try {
                $manager->disable($slug);
                $this->fail('Disable hook failure should abort the operation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('intentional disable hook failure', $exception->getMessage());
            }

            $this->assertDatabaseHas('plugins', ['slug' => $slug, 'is_enabled' => true]);

            PluginRecord::query()->where('slug', $slug)->update(['is_enabled' => false]);
            try {
                $manager->uninstall($slug);
                $this->fail('Uninstall hook failure should abort and roll back the operation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('intentional uninstall hook failure', $exception->getMessage());
            }

            $this->assertDatabaseHas('plugins', [
                'slug' => $slug,
                'is_installed' => true,
                'is_enabled' => false,
            ]);
        } finally {
            File::deleteDirectory($pluginDir);
        }
    }

    public function test_core_cannot_be_disabled_or_uninstalled(): void
    {
        $this->markSystemInstalled();
        PluginRecord::query()->create([
            'slug' => 'aec-lms-core',
            'name' => 'AEC LMS Core',
            'version' => '2.0.0',
            'is_core' => true,
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        $manager = app(PluginManager::class);

        $this->expectException(RuntimeException::class);
        $manager->disable('aec-lms-core');
    }

    public function test_plugin_settings_encrypt_secrets_and_uninstall_preserves_data(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management']);

        DB::table('branches')->insert([
            'code' => 'DHK-01',
            'name' => 'Dhaka Main',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-sslcommerz', ['store_id' => 'aec_store', 'store_password' => 'super-secret'], ['store_password']);

        $rawRow = PluginSetting::query()->where('plugin_slug', 'gateway-sslcommerz')->where('setting_key', 'store_password')->firstOrFail();
        $this->assertTrue($rawRow->is_encrypted);
        $this->assertNotSame('super-secret', $rawRow->setting_value);
        $this->assertSame('super-secret', $manager->getSetting('gateway-sslcommerz', 'store_password'));

        $manager->disable('branch-management');
        $manager->uninstall('branch-management', false);

        // Business table & data must remain intact after plugin uninstall
        $this->assertSame(1, DB::table('branches')->count());
    }

    public function test_separated_builders_install_and_uninstall_independently_while_preserving_data(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder', 'slider-builder', 'carousel-builder', 'cms-website']);

        DB::table('cms_pages')->insert([
            'slug' => 'ielts-guarantee',
            'title' => 'IELTS Band Guarantee',
            'blocks_json' => json_encode([['type' => 'rich_text', 'content' => 'Custom landing page']]),
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cms_sliders')->insert([
            'key_name' => 'hero',
            'title' => 'Fall Intake Open',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cms_carousels')->insert([
            'carousel_group' => 'testimonial_left',
            'title' => 'Structured mocks boosted my confidence.',
            'subtitle' => 'Tasnim Akter · Band 8.0',
            'rating' => 5,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = app(PluginManager::class);
        $manager->disable('page-builder');
        $manager->uninstall('page-builder', false);

        // Other builders remain enabled and page data stays intact
        $this->assertTrue($manager->isEnabled('slider-builder'));
        $this->assertTrue($manager->isEnabled('carousel-builder'));
        $this->assertTrue($manager->isEnabled('cms-website'));
        $this->assertSame(1, DB::table('cms_pages')->count());
    }


    public function test_plugin_catalog_dynamically_autoloads_new_module_namespaces(): void
    {
        $this->markSystemInstalled();

        $slug = 'runtime-autoload-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        $providerDir = $pluginDir . DIRECTORY_SEPARATOR . 'Providers';
        File::ensureDirectoryExists($providerDir);

        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Runtime Autoload Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
            'provider' => 'Plugins\\Modules\\RuntimeAutoloadProbe\\Providers\\RuntimeAutoloadProbeServiceProvider',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($providerDir . DIRECTORY_SEPARATOR . 'RuntimeAutoloadProbeServiceProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Plugins\Modules\RuntimeAutoloadProbe\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class RuntimeAutoloadProbeServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'runtime-autoload-probe';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
PHP);

        try {
            $manifest = app(PluginManager::class)->discoverManifests()[$slug] ?? null;
            $this->assertNotNull($manifest);
            $this->assertTrue(class_exists($manifest->providerClass));
            $this->assertInstanceOf(
                \App\Core\Contracts\PluginInterface::class,
                app()->resolveProvider($manifest->providerClass)
            );
        } finally {
            File::deleteDirectory($pluginDir);
        }
    }


    public function test_failed_plugin_install_restores_module_files_and_catalog_state(): void
    {
        $this->markSystemInstalled();

        $slug = 'rollback-probe';
        $zipPath = sys_get_temp_dir() . '/rollback_probe_' . uniqid('', true) . '.zip';
        $targetDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        $manifest = json_encode([
            'slug' => $slug,
            'name' => 'Rollback Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
            'provider' => 'Plugins\\Modules\\RollbackProbe\\Providers\\RollbackProbeServiceProvider',
        ], JSON_THROW_ON_ERROR);
        $provider = <<<'PHP'
<?php

declare(strict_types=1);

namespace Plugins\Modules\RollbackProbe\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use RuntimeException;

class RollbackProbeServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'rollback-probe';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function onInstall(): void
    {
        throw new RuntimeException('intentional install hook failure');
    }
}
PHP;
        $checksums = json_encode([
            'plugin.json' => hash('sha256', $manifest),
            'Providers/RollbackProbeServiceProvider.php' => hash('sha256', $provider),
        ], JSON_THROW_ON_ERROR);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString($slug . '/plugin.json', $manifest);
        $zip->addFromString($slug . '/Providers/RollbackProbeServiceProvider.php', $provider);
        $zip->addFromString($slug . '/checksums.json', $checksums);
        $zip->close();

        $caught = null;
        try {
            app(PluginManager::class)->installOrUpdateFromZip($zipPath);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            @unlink($zipPath);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringContainsString('automatically rolled back', $caught->getMessage());
        $this->assertStringContainsString('intentional install hook failure', $caught->getMessage());
        $this->assertDatabaseMissing('plugins', ['slug' => $slug]);
        $this->assertDirectoryDoesNotExist($targetDir);
        File::deleteDirectory($targetDir);
    }

    public function test_zip_uploader_rejects_path_traversal_and_corrupted_checksums(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);

        $traversalZip = sys_get_temp_dir() . '/evil_traversal_' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($traversalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('../evil.php', '<?php echo "pwn";');
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe ZIP path rejected');
            $manager->installOrUpdateFromZip($traversalZip);
        } finally {
            @unlink($traversalZip);
        }
    }

    public function test_zip_update_rolls_back_automatically_when_integrity_fails_or_manifest_incompatible(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $slug = 'sandbox-rollback-check';
        $targetDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;

        $badChecksumZip = sys_get_temp_dir() . '/bad_checksum_' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($badChecksumZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString("{$slug}/plugin.json", json_encode([
            'slug' => $slug,
            'name' => 'Sandbox Plugin',
            'version' => '2.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        $zip->addFromString("{$slug}/checksums.json", json_encode([
            'plugin.json' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SHA-256 mismatch');
            $manager->installOrUpdateFromZip($badChecksumZip);
        } finally {
            @unlink($badChecksumZip);
            File::deleteDirectory($targetDir);
        }
    }

    public function test_zip_uploader_rejects_unix_symlink_entries_before_extraction(): void
    {
        $this->markSystemInstalled();

        $zipPath = sys_get_temp_dir() . '/symlink_plugin_' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('symlink.php', '../../outside.php');
        $this->assertTrue($zip->setExternalAttributesName(
            'symlink.php',
            ZipArchive::OPSYS_UNIX,
            (0120777 << 16)
        ));
        $zip->close();

        try {
            app(PluginManager::class)->installOrUpdateFromZip($zipPath);
            $this->fail('Unix symlink entries must be rejected before extraction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Symlink entry blocked in ZIP', $exception->getMessage());
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_all_plugin_manifests_use_optional_integrations_not_install_blocking_sibling_dependencies(): void
    {
        $directories = \Illuminate\Support\Facades\File::directories(base_path('plugins/modules'));
        $this->assertCount(39, $directories);

        foreach ($directories as $directory) {
            $manifest = \App\Core\Plugins\PluginManifest::fromDirectory($directory);
            $this->assertSame([], $manifest->dependencies, $manifest->slug . ' must not require a sibling plugin to install');
        }
    }


    public function test_all_plugin_manifests_have_unique_slugs_and_no_required_sibling_dependencies(): void
    {
        $this->markSystemInstalled();

        $directories = File::directories(base_path('plugins/modules'));
        $manifests = [];
        foreach ($directories as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!File::exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($manifest, 'Plugin manifest must decode to an object: ' . $manifestPath);
            $this->assertNotEmpty($manifest['slug'] ?? null, 'Plugin manifest slug is required: ' . $manifestPath);
            $this->assertSame(basename($directory), $manifest['slug'], 'Manifest slug must match its plugin folder.');
            $this->assertSame([], $manifest['dependencies'] ?? [], 'Required sibling dependencies are not allowed: ' . $manifestPath);
            $this->assertIsArray($manifest['optional_dependencies'] ?? [], 'Optional integrations must be an array: ' . $manifestPath);
            $manifests[] = $manifest['slug'];
        }

        $this->assertCount(39, $manifests, 'The source audit must cover all 39 plugin manifests.');
        $this->assertCount(count(array_unique($manifests)), $manifests, 'Plugin slugs must be unique.');
    }

    public function test_reset_plugin_settings_restores_manifest_defaults_and_preserves_business_records(): void
    {
        $this->markSystemInstalled();
        $slug = 'settings-reset-probe';
        $directory = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($directory);

        File::put($directory . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Settings Reset Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
            'settings_schema' => [
                ['key' => 'site_label', 'type' => 'text', 'default' => 'Default label'],
                ['key' => 'api_token', 'type' => 'password', 'secret' => true, 'default' => ''],
            ],
        ], JSON_THROW_ON_ERROR));

        $admin = $this->createUserWithRole('admin');
        try {
            $manager = app(PluginManager::class);
            $manager->saveSettings($slug, [
                'site_label' => 'Custom label',
                'api_token' => 'sensitive-token',
                'unknown_old_key' => 'legacy value',
            ], ['api_token'], $admin->id);

            $userCountBefore = DB::table('users')->count();
            $manager->resetSettingsToDefaults($slug, $admin->id);

            $this->assertSame('Default label', $manager->getSetting($slug, 'site_label'));
            $this->assertSame('', $manager->getSetting($slug, 'api_token'));
            $this->assertNull($manager->getSetting($slug, 'unknown_old_key'));

            $secretRow = PluginSetting::query()
                ->where('plugin_slug', $slug)
                ->where('setting_key', 'api_token')
                ->firstOrFail();
            $this->assertFalse($secretRow->is_encrypted, 'An empty reset credential must not be marked as ciphertext.');
            $backups = glob(storage_path('app/plugin-setting-backups/' . $slug . '-*.json')) ?: [];
            $this->assertNotEmpty($backups, 'Reset must create a private settings backup before changing values.');
            $this->assertStringNotContainsString('sensitive-token', (string) file_get_contents($backups[0]));
            $this->assertSame($userCountBefore, DB::table('users')->count());
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'plugin.settings_reset',
                'entity_type' => 'plugin',
                'entity_id' => $slug,
            ]);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_plugin_delete_restore_and_permanent_delete_preserve_business_data_and_sibling_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);

        DB::table('branches')->insert([
            'code' => 'RB-01',
            'name' => 'Recycle Bin Preservation Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = app(PluginManager::class);
        $metadata = $manager->deleteToRecycleBin('branch-management');

        $this->assertDirectoryDoesNotExist(base_path('plugins/modules/branch-management'));
        $this->assertFalse($manager->isInstalled('branch-management'));
        $this->assertTrue($manager->isEnabled('student-management'));
        $this->assertDatabaseHas('branches', ['code' => 'RB-01']);
        $this->assertSame($metadata['archive'], $manager->recycledPlugins()[0]['archive']);

        $restored = $manager->restoreFromRecycleBin($metadata['archive']);
        $this->assertNotNull($restored);
        $this->assertTrue($manager->isInstalled('branch-management'));
        $this->assertTrue($manager->isEnabled('branch-management'));
        $this->assertTrue($manager->isEnabled('student-management'));
        $this->assertDatabaseHas('branches', ['code' => 'RB-01']);

        $secondDelete = $manager->deleteToRecycleBin('branch-management');
        $manager->permanentlyDeleteFromRecycleBin($secondDelete['archive']);
        $permanentBackup = storage_path('app/plugin-permanent-delete-backups/' . $secondDelete['archive']);
        $this->assertDirectoryExists($permanentBackup);
        $this->assertSame([], $manager->recycledPlugins());
        $this->assertDatabaseHas('branches', ['code' => 'RB-01']);

        // Restore the source fixture from its verified private backup so later
        // tests can independently exercise the same plugin package.
        $sourcePackage = base_path('plugins/modules/branch-management');
        $this->assertTrue(File::copyDirectory($permanentBackup, $sourcePackage));
        $this->assertFileExists($sourcePackage . '/plugin.json');
        $this->assertTrue($manager->isEnabled('student-management'));
    }

    public function test_recycle_bin_restore_rejects_tampered_plugin_files(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management']);

        $manager = app(PluginManager::class);
        $metadata = $manager->deleteToRecycleBin('branch-management');
        $archivePath = storage_path('app/plugin-recycle-bin/' . $metadata['archive']);
        $files = File::allFiles($archivePath);
        $this->assertNotEmpty($files);

        $tamperedFile = $files[0]->getPathname();
        $originalContents = File::get($tamperedFile);
        File::append($tamperedFile, "\n// tampered after recycle-bin deletion");

        try {
            $manager->restoreFromRecycleBin($metadata['archive']);
            $this->fail('Tampered plugin files must never be restored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('integrity', strtolower($exception->getMessage()));
        } finally {
            File::put($tamperedFile, $originalContents);
        }

        $restored = $manager->restoreFromRecycleBin($metadata['archive']);
        $this->assertNotNull($restored);
        $this->assertTrue($manager->isEnabled('branch-management'));
    }

}
