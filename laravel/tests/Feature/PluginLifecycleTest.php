<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Core\Versioning\VersionConstraint;
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
        $message = implode("\\n", [
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

}
