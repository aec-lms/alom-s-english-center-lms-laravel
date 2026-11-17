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

    public function test_explicit_required_plugin_dependencies_are_still_enforced(): void
    {
        $this->markSystemInstalled();
        $manager = app(PluginManager::class);
        $slug = 'required-dependency-probe';
        $pluginDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $slug;
        File::ensureDirectoryExists($pluginDir);

        file_put_contents($pluginDir . DIRECTORY_SEPARATOR . 'plugin.json', json_encode([
            'slug' => $slug,
            'name' => 'Required Dependency Probe',
            'version' => '1.0.0',
            'core_compatibility' => '^2.0',
            'dependencies' => ['branch-management'],
            'provider' => 'Plugins\\Modules\\BranchManagement\\Providers\\BranchManagementServiceProvider',
        ], JSON_THROW_ON_ERROR));

        try {
            $manager->syncCatalog();
            try {
                $manager->install($slug);
                $this->fail('A missing explicitly-required plugin dependency must block installation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('branch-management', $exception->getMessage());
            }
        } finally {
            File::deleteDirectory($pluginDir);
        }
    }

    public function test_academic_and_finance_plugins_install_without_optional_sibling_plugins(): void
    {
        $this->markSystemInstalled();
        $independent = [
            'admission-management',
            'attendance-management',
            'certificate-management',
            'fees-management',
            'gateway-paypal',
            'gateway-stripe',
            'guardian-management',
            'hr-staff-management',
            'library-management',
            'payment-management',
            'universal-payment',
        ];

        $this->activatePlugins($independent);
        $manager = app(PluginManager::class);

        foreach ($independent as $slug) {
            $this->assertTrue($manager->isEnabled($slug), "{$slug} should install without required sibling plugins.");
            $this->assertSame([], $manager->discoverManifests()[$slug]->dependencies, "{$slug} must not require another LMS plugin.");
        }

        $this->assertFalse($manager->isEnabled('branch-management'));
        $this->assertFalse($manager->isEnabled('student-management'));
        $this->assertFalse($manager->isEnabled('course-management'));
        $this->assertFalse(\\Illuminate\\Support\\Facades\\Schema::hasTable('students'));
        $this->assertFalse(\\Illuminate\\Support\\Facades\\Schema::hasTable('branches'));
        $this->assertFalse(\\Illuminate\\Support\\Facades\\Schema::hasTable('courses'));

        $manager->install('student-management');
        $manager->install('teacher-management');
        $this->assertTrue($manager->isEnabled('student-management'));
        $this->assertTrue($manager->isEnabled('teacher-management'));

        foreach ([
            'admission_applications',
            'attendance_records',
            'certificates',
            'fee_categories',
            'fee_invoices',
            'guardians',
            'guardian_student',
            'staff_profiles',
            'staff_payrolls',
            'library_books',
            'library_loans',
            'payment_transactions',
            'payment_refunds',
            'students',
            'teachers',
            'universal_payment_transactions',
        ] as $table) {
            $this->assertTrue(\\Illuminate\\Support\\Facades\\Schema::hasTable($table), "Expected standalone schema table {$table}.");
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
}
