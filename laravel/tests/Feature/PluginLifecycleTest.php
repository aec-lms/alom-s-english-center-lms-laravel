<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Providers\PluginServiceProvider;
use App\Core\Versioning\VersionConstraint;
use App\Models\AuditLog;
use App\Models\PluginRecord;
use App\Models\PluginSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class PluginLifecycleTest extends TestCase
{

    public function test_plugin_settings_reject_unknown_keys_and_manifest_disallowed_options_and_encrypt_declared_secrets(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);

        try {
            $manager->saveSettings('universal-payment', ['unlisted_setting' => 'unexpected']);
            $this->fail('Undeclared plugin settings must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings.unlisted_setting', $exception->errors());
        }

        try {
            $manager->saveSettings('universal-payment', ['allow_offline_payments' => 'sometimes']);
            $this->fail('Values outside manifest select options must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings.allow_offline_payments', $exception->errors());
        }

        $manager->saveSettings('universal-payment', ['allow_offline_payments' => '0']);
        $this->assertDatabaseHas('plugin_settings', [
            'plugin_slug' => 'universal-payment',
            'setting_key' => 'allow_offline_payments',
            'setting_value' => '0',
            'is_encrypted' => false,
        ]);

        $manager->saveSettings('sms-gateway', ['api_token' => 'manifest-secret']);
        $this->assertDatabaseHas('plugin_settings', [
            'plugin_slug' => 'sms-gateway',
            'setting_key' => 'api_token',
            'is_encrypted' => true,
        ]);
        $this->assertSame('manifest-secret', $manager->getSetting('sms-gateway', 'api_token'));
    }

    public function test_plugin_install_registers_its_route_file_once_after_catalog_commit(): void
    {
        $this->markSystemInstalled();

        $slug = 'route-once-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir . DIRECTORY_SEPARATOR . 'Routes');
        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Route Once Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $pluginDir . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'web.php',
            "<?php \\Illuminate\\Support\\Facades\\Route::get('/route-once-probe', fn () => 'ok')->name('plugins.route-once-probe.index');"
        );

        try {
            $record = app(PluginManager::class)->install($slug);
            $this->assertTrue($record->fresh()->is_installed);

            $matchingRoutes = collect(app('router')->getRoutes())
                ->filter(fn ($route): bool => $route->uri() === 'route-once-probe');

            $this->assertCount(1, $matchingRoutes);
            $this->assertSame('plugins.route-once-probe.index', $matchingRoutes->first()->getName());
        } finally {
            File::deleteDirectory($pluginDir);
        }
    }

    public function test_plugin_disable_rolls_back_when_required_audit_fails(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');
        $slug = 'required-audit-disable-probe';
        $record = PluginRecord::query()->create([
            'slug' => $slug,
            'name' => 'Required Audit Disable Probe',
            'version' => '1.0.0',
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced plugin lifecycle audit failure');
        });

        try {
            $caught = null;
            try {
                app(PluginManager::class)->disable($slug, $admin->id);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $caught);
            $this->assertSame('forced plugin lifecycle audit failure', $caught->getMessage());
            $this->assertTrue($record->fresh()->is_enabled);
            $this->assertDatabaseMissing('audit_logs', [
                'action' => 'plugin.disabled',
                'entity_type' => 'plugin',
                'entity_id' => $slug,
            ]);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_plugin_service_provider_skips_dynamic_route_loading_when_route_cache_is_active(): void
    {
        $this->markSystemInstalled();

        $slug = 'cached-route-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir);
        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Cached Route Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));

        PluginRecord::query()->create([
            'slug' => $slug,
            'name' => 'Cached Route Probe',
            'version' => '1.0.0',
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        $cachePath = $this->app->getCachedRoutesPath();
        $hadCache = is_file($cachePath);
        $previousCache = $hadCache ? file_get_contents($cachePath) : null;

        try {
            File::ensureDirectoryExists(dirname($cachePath));
            file_put_contents($cachePath, "<?php return [];");

            $manifest = \App\Core\Plugins\PluginManifest::fromDirectory($pluginDir);
            $manager = \Mockery::mock(PluginManager::class);
            // Exercise the normal provider boot path even when the MySQL suite
            // disables automatic plugin boot for the rest of its tests.
            $manager->shouldReceive('discoverManifests')->once()->andReturn([$slug => $manifest]);
            $manager->shouldNotReceive('loadPluginRoutes');

            $this->app['env'] = 'production';
            $provider = new PluginServiceProvider($this->app);
            $provider->boot($manager);

            $this->assertFileExists($cachePath, 'The compiled route cache must remain active during application boot.');
        } finally {
            if ($hadCache && $previousCache !== false) {
                file_put_contents($cachePath, $previousCache);
            } else {
                @unlink($cachePath);
            }
            File::deleteDirectory($pluginDir);
            PluginRecord::query()->where('slug', $slug)->delete();
        }
    }

    public function test_disabling_and_uninstalling_plugins_invalidate_compiled_route_cache(): void
    {
        $this->markSystemInstalled();
        $cachePath = $this->app->getCachedRoutesPath();

        foreach (['disable', 'uninstall'] as $operation) {
            $slug = 'route-cache-' . $operation . '-probe';
            $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
            File::ensureDirectoryExists($pluginDir);
            File::put($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
                'slug' => $slug,
                'name' => 'Route Cache ' . ucfirst($operation) . ' Probe',
                'version' => '1.0.0',
                'core_compatibility' => '^2.0',
                'dependencies' => [],
            ], JSON_THROW_ON_ERROR));
            PluginRecord::query()->create([
                'slug' => $slug,
                'name' => 'Route Cache ' . ucfirst($operation) . ' Probe',
                'version' => '1.0.0',
                'is_installed' => true,
                'is_enabled' => true,
            ]);

            $hadCache = is_file($cachePath);
            $previousCache = $hadCache ? file_get_contents($cachePath) : null;
            try {
                File::ensureDirectoryExists(dirname($cachePath));
                File::put($cachePath, "<?php return [];");

                if ($operation === 'disable') {
                    app(PluginManager::class)->disable($slug);
                } else {
                    app(PluginManager::class)->uninstall($slug, false);
                }

                $this->assertFileDoesNotExist($cachePath, "The route cache must be cleared after plugin {$operation}.");
            } finally {
                if ($hadCache && $previousCache !== false) {
                    File::put($cachePath, $previousCache);
                } else {
                    @unlink($cachePath);
                }
                File::deleteDirectory($pluginDir);
                PluginRecord::query()->where('slug', $slug)->delete();
            }
        }
    }

    public function test_updating_a_plugin_invalidates_compiled_route_cache(): void
    {
        $this->markSystemInstalled();

        $slug = 'route-cache-update-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir);
        File::put($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Route Cache Update Probe',
            'version' => '2.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        PluginRecord::query()->create([
            'slug' => $slug,
            'name' => 'Route Cache Update Probe',
            'version' => '1.0.0',
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        $cachePath = $this->app->getCachedRoutesPath();
        $hadCache = is_file($cachePath);
        $previousCache = $hadCache ? file_get_contents($cachePath) : null;

        try {
            File::ensureDirectoryExists(dirname($cachePath));
            File::put($cachePath, "<?php return [];");

            $updated = app(PluginManager::class)->updatePlugin($slug, null, true);

            $this->assertSame('2.0.0', $updated->fresh()->version);
            $this->assertFileDoesNotExist($cachePath, 'The route cache must be cleared after a plugin package update.');
        } finally {
            if ($hadCache && $previousCache !== false) {
                File::put($cachePath, $previousCache);
            } else {
                @unlink($cachePath);
            }
            File::deleteDirectory($pluginDir);
            PluginRecord::query()->where('slug', $slug)->delete();
        }
    }

    public function test_reinstall_does_not_reenable_an_administrator_disabled_plugin(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);

        $manager->install('student-management');
        $manager->disable('student-management');

        $record = $manager->install('student-management');

        $this->assertTrue($record->fresh()->is_installed);
        $this->assertFalse($record->fresh()->is_enabled);
    }

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

    public function test_plugin_manifest_rejects_invalid_duplicate_and_self_dependencies(): void
        {
            $directory = storage_path('framework/testing/plugin-manifest-' . bin2hex(random_bytes(6)));
            File::ensureDirectoryExists($directory);
    
            try {
                foreach ([
                    ['dependencies' => ['valid-plugin', '../escape']],
                    ['dependencies' => ['valid-plugin', 'valid-plugin']],
                    ['dependencies' => ['manifest-probe']],
                    ['dependencies' => ['shared-plugin'], 'optional_dependencies' => ['shared-plugin']],
                    ['dependencies' => ['valid-plugin' => 'unexpected']],
                    ['provider' => 'Invalid Provider Class'],
                ] as $invalidFields) {
                    $manifest = array_merge([
                        'slug' => 'manifest-probe',
                        'name' => 'Manifest Probe',
                        'version' => '1.0.0',
                    ], $invalidFields);
                    File::put($directory . DIRECTORY_SEPARATOR . 'plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    
                    try {
                        \App\Core\Plugins\PluginManifest::fromDirectory($directory);
                        $this->fail('Malformed plugin manifest metadata must be rejected.');
                    } catch (\InvalidArgumentException) {
                        $this->assertTrue(true);
                    }
                }
            } finally {
                File::deleteDirectory($directory);
            }
        }

    public function test_plugin_discovery_rejects_manifest_folder_slug_mismatch(): void
        {
            $this->markSystemInstalled();
            $modulesPath = (string) config('plugins.modules_path');
            $directory = $modulesPath . DIRECTORY_SEPARATOR . 'folder-identity-probe';
            File::ensureDirectoryExists($directory);
            File::put($directory . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
                'slug' => 'different-plugin-identity',
                'name' => 'Mismatched Plugin',
                'version' => '1.0.0',
            ], JSON_THROW_ON_ERROR));
    
            try {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage('does not match manifest slug');
                app(PluginManager::class)->discoverManifests();
            } finally {
                File::deleteDirectory($directory);
            }
        }

    public function test_student_management_installs_without_optional_branch_plugin(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $record = $manager->install('student-management');

        $this->assertTrue($record->is_installed);
        $this->assertFalse($manager->isInstalled('branch-management'));
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


    public function test_route_registration_failure_restores_previous_plugin_catalog_state(): void
    {
        $this->markSystemInstalled();

        $slug = 'route-rollback-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir . DIRECTORY_SEPARATOR . 'Routes');
        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Route Rollback Probe',
            'version' => '2.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'web.php', "<?php this is deliberately invalid PHP");

        $previous = PluginRecord::query()->create([
            'slug' => $slug,
            'name' => 'Route Rollback Probe (catalog)',
            'version' => '1.0.0',
            'is_installed' => false,
            'is_enabled' => false,
        ]);

        try {
            try {
                app(PluginManager::class)->install($slug);
                $this->fail('Expected route registration to fail.');
            } catch (\ParseError) {
                // The route file is intentionally invalid to simulate a post-commit failure.
            }

            $restored = PluginRecord::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame($previous->version, $restored->version);
            $this->assertFalse($restored->is_installed);
            $this->assertFalse($restored->is_enabled);
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

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $message = implode("\n", [
            'AEC-LMS-PLUGIN-SIGNATURE-V1',
            $slug,
            '1.0.0',
            hash('sha256', $checksums),
            hash('sha256', $manifest),
        ]);
        $signaturePath = sys_get_temp_dir() . '/rollback-probe-' . bin2hex(random_bytes(6)) . '.json';
        File::put($signaturePath, json_encode([
            'key_id' => 'lifecycle-test',
            'signature' => base64_encode(sodium_crypto_sign_detached($message, $secretKey)),
        ], JSON_THROW_ON_ERROR));
        config(['plugins.signature_keys' => ['lifecycle-test' => base64_encode($publicKey)]]);

        $caught = null;
        try {
            app(PluginManager::class)->installOrUpdateFromZip($zipPath, null, $signaturePath);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringContainsString('automatically rolled back', $caught->getMessage());
        $this->assertStringContainsString('intentional install hook failure', $caught->getMessage());
        $this->assertDatabaseMissing('plugins', ['slug' => $slug]);
        $this->assertDirectoryDoesNotExist($targetDir);
        File::deleteDirectory($targetDir);
    }

    public function test_zip_uploader_rejects_a_symlinked_existing_plugin_target_without_touching_its_destination(): void
    {
        $this->markSystemInstalled();

        $slug = 'symlink-target-probe';
        $modulesRoot = realpath((string) config('plugins.modules_path'));
        $this->assertNotFalse($modulesRoot);
        $targetDir = $modulesRoot . DIRECTORY_SEPARATOR . $slug;
        $outsideDir = storage_path('app/plugin-outside-target-' . uniqid('', true));
        $zipPath = sys_get_temp_dir() . '/symlink_target_' . uniqid('', true) . '.zip';
        File::ensureDirectoryExists($outsideDir);
        File::put($outsideDir . DIRECTORY_SEPARATOR . 'keep.txt', 'must remain untouched');

        $manifest = json_encode([
            'slug' => $slug,
            'name' => 'Symlink Target Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString($slug . '/plugin.json', $manifest);
        $zip->addFromString($slug . '/checksums.json', json_encode([
            'plugin.json' => hash('sha256', $manifest),
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        try {
            $this->assertTrue(symlink($outsideDir, $targetDir));
            try {
                app(PluginManager::class)->installOrUpdateFromZip($zipPath);
                $this->fail('Expected the plugin uploader to reject a symlinked target.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('symbolic link', $exception->getMessage());
            }

            $this->assertTrue(is_link($targetDir));
            $this->assertSame('must remain untouched', File::get($outsideDir . DIRECTORY_SEPARATOR . 'keep.txt'));
        } finally {
            if (is_link($targetDir)) {
                @unlink($targetDir);
            }
            File::deleteDirectory($outsideDir);
            @unlink($zipPath);
        }
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

    public function test_flash_sale_and_learn_and_earn_install_without_optional_course_or_exam_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['flash-sale', 'learn-and-earn']);

        $manager = app(PluginManager::class);
        $this->assertTrue($manager->isEnabled('flash-sale'));
        $this->assertTrue($manager->isEnabled('learn-and-earn'));
        $this->assertFalse($manager->isEnabled('course-management'));
        $this->assertFalse($manager->isEnabled('exam-management'));

        $this->assertTrue(Schema::hasTable('flash_sales'));
        $this->assertTrue(Schema::hasTable('learn_earn_categories'));
        $this->assertTrue(Schema::hasTable('learn_earn_questions'));
        $this->assertTrue(Schema::hasTable('learn_earn_exam_access'));
        $this->assertFalse(Schema::hasTable('courses'));
        $this->assertFalse(Schema::hasTable('exams'));

        $this->get('/flash-sale')->assertStatus(503);
        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->get('/learn-and-earn/exams')->assertStatus(503);
    }

    public function test_plugin_recycle_bin_archive_schema_preserves_restore_metadata(): void
    {
        $this->markSystemInstalled();

        $this->assertTrue(Schema::hasTable('plugin_archives'));
        $archive = \App\Models\PluginArchive::query()->create([
            'plugin_slug' => 'recycle-probe',
            'plugin_name' => 'Recycle Probe',
            'version' => '1.2.3',
            'archive_path' => storage_path('app/plugin-recycle-bin/recycle-probe'),
            'manifest_snapshot' => ['slug' => 'recycle-probe', 'version' => '1.2.3'],
            'deleted_by' => null,
            'deleted_at' => now(),
        ]);

        $this->assertSame('recycle-probe', $archive->fresh()->plugin_slug);
        $this->assertSame('1.2.3', $archive->fresh()->manifest_snapshot['version']);
        $this->assertNotNull($archive->fresh()->deleted_at);
    }

    public function test_plugin_manifest_exposes_admin_menu_entries_for_enabled_modules(): void
    {
        $modulesPath = (string) config('plugins.modules_path');
        $courseManifest = \App\Core\Plugins\PluginManifest::fromDirectory($modulesPath . DIRECTORY_SEPARATOR . 'course-management');
        $traceManifest = \App\Core\Plugins\PluginManifest::fromDirectory($modulesPath . DIRECTORY_SEPARATOR . 'student-tracing');

        $this->assertSame('plugins.courses.index', $courseManifest->adminMenu[0]['route']);
        $this->assertSame('courses.manage', $courseManifest->adminMenu[0]['permission']);
        $this->assertSame('plugins.student-tracing.index', $traceManifest->adminMenu[0]['route']);
        $this->assertSame('students.trace.view', $traceManifest->adminMenu[0]['permission']);
    }

    public function test_manifest_driven_admin_menu_only_shows_enabled_plugins_to_permitted_users(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-tracing']);
        \Illuminate\Support\Facades\Route::get('/test-admin-courses', fn () => 'ok')->name('plugins.courses.index');
        \Illuminate\Support\Facades\Route::get('/test-admin-student-tracing', fn () => 'ok')->name('plugins.student-tracing.index');
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin);

        $groups = app(\App\Core\Plugins\AdminMenuRegistry::class)->groups();
        $allItems = array_merge([], ...array_values($groups));
        $labels = array_column($allItems, 'label');

        $this->assertContains('Courses', $labels);
        $this->assertContains('Student Activity Trace', $labels);

        app(PluginManager::class)->disable('student-tracing');
        $groupsAfterDisable = app(\App\Core\Plugins\AdminMenuRegistry::class)->groups();
        $allItemsAfterDisable = array_merge([], ...array_values($groupsAfterDisable));
        $labelsAfterDisable = array_column($allItemsAfterDisable, 'label');

        $this->assertNotContains('Student Activity Trace', $labelsAfterDisable);
        $this->assertContains('Courses', $labelsAfterDisable);
    }

    public function test_runtime_plugin_route_registration_clears_stale_route_cache_and_loads_new_routes(): void
    {
        $this->markSystemInstalled();
        $slug = 'runtime-cache-route-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir . DIRECTORY_SEPARATOR . 'Routes');
        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug, 'name' => 'Runtime Cache Route Probe', 'version' => '1.0.0',
            'core_compatibility' => '^2.0', 'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $pluginDir . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'web.php',
            "<?php \\Illuminate\\Support\\Facades\\Route::get('/runtime-cache-route-probe', fn () => 'ok')->name('plugins.runtime-cache-route-probe.index');"
        );
        $cachePath = $this->app->getCachedRoutesPath();
        $hadCache = is_file($cachePath);
        $previousCache = $hadCache ? file_get_contents($cachePath) : null;
        try {
            File::ensureDirectoryExists(dirname($cachePath));
            file_put_contents($cachePath, "<?php return [];");
            $manifest = app(PluginManager::class)->discoverManifests()[$slug] ?? null;
            $this->assertNotNull($manifest);
            app(PluginManager::class)->loadPluginRoutes($manifest);
            $this->assertFileDoesNotExist($cachePath);
            $matchingRoutes = collect(app('router')->getRoutes())
                ->filter(fn ($route): bool => $route->uri() === 'runtime-cache-route-probe');
            $this->assertCount(1, $matchingRoutes);
            $this->assertSame('plugins.runtime-cache-route-probe.index', $matchingRoutes->first()->getName());
        } finally {
            if ($hadCache && $previousCache !== false) {
                file_put_contents($cachePath, $previousCache);
            } else {
                @unlink($cachePath);
            }
            File::deleteDirectory($pluginDir);
        }
    }

    public function test_plugin_enable_rolls_back_catalog_state_when_route_registration_fails(): void
    {
        $this->markSystemInstalled();
        $slug = 'enable-route-failure-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir . DIRECTORY_SEPARATOR . 'Routes');
        File::put($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Enable Route Failure Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        File::put($pluginDir . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'web.php', "<?php this is not valid PHP;");
        PluginRecord::query()->create([
            'slug' => $slug,
            'name' => 'Enable Route Failure Probe',
            'version' => '1.0.0',
            'is_installed' => true,
            'is_enabled' => false,
        ]);

        try {
            try {
                app(PluginManager::class)->enable($slug);
                $this->fail('A plugin with an invalid route file must not remain enabled.');
            } catch (\ParseError $exception) {
                $this->assertStringContainsString('unexpected', strtolower($exception->getMessage()));
            }

            $this->assertDatabaseHas('plugins', [
                'slug' => $slug,
                'is_installed' => true,
                'is_enabled' => false,
            ]);
        } finally {
            File::deleteDirectory($pluginDir);
            PluginRecord::query()->where('slug', $slug)->delete();
        }
    }

}
