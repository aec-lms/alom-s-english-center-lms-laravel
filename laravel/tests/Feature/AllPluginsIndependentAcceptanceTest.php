<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Core\Plugins\PluginManifest;
use App\Models\PluginRecord;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class AllPluginsIndependentAcceptanceTest extends TestCase
{
    public static function pluginSlugs(): array
    {
        return [
            ['admin-boosting'], ['admission-management'], ['attendance-management'],
            ['batch-management'], ['branch-management'], ['carousel-builder'],
            ['certificate-management'], ['class-management'], ['cms-website'],
            ['course-management'], ['email-notifications'], ['exam-management'],
            ['fees-management'], ['gateway-card'], ['gateway-paypal'],
            ['gateway-sslcommerz'], ['gateway-stripe'], ['gmail-marketing'],
            ['google-console'], ['guardian-management'], ['hr-staff-management'],
            ['library-management'], ['marketing-management'], ['meta-pixel'],
            ['mobile-app-api'], ['notice-board'], ['notification-center'],
            ['page-builder'], ['payment-management'], ['reports-analytics'],
            ['role-ai-assistant'], ['seo-manager'], ['slider-builder'],
            ['sms-gateway'], ['storefront-catalog'], ['student-management'],
            ['teacher-management'], ['universal-payment'], ['visitor-popup-ads'],
        ];
    }

    #[DataProvider('pluginSlugs')]
    public function test_each_plugin_installs_and_runs_its_lifecycle_without_sibling_plugins(string $slug): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $directory = base_path('plugins/modules/' . $slug);
        $this->assertDirectoryExists($directory, "Missing plugin directory: {$slug}");
        $this->assertFileExists($directory . '/plugin.json', "Missing manifest: {$slug}");

        $manifest = PluginManifest::fromDirectory($directory);
        $this->assertSame($slug, $manifest->slug);
        $this->assertSame([], $manifest->dependencies, "{$slug} must not require a sibling plugin");

        $providerClass = $manifest->providerClass;
        $this->assertNotNull($providerClass, "{$slug} must declare a service provider");
        $this->assertTrue(class_exists($providerClass), "{$slug} provider class must autoload");

        $manager->install($slug);

        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
        $this->assertTrue($record->is_installed, "{$slug} did not install");
        $this->assertTrue($record->is_enabled, "{$slug} did not enable on install");

        foreach ($manifest->optionalDependencies as $optionalSlug) {
            $this->assertFalse(
                PluginRecord::query()->where('slug', $optionalSlug)->where('is_installed', true)->exists(),
                "{$slug} unexpectedly installed optional sibling {$optionalSlug}"
            );
        }

        // Exercise a real checksum-verified ZIP update for every plugin package.
        $originalManifest = File::get($directory . '/plugin.json');
        $packageRoot = storage_path('app/plugin-acceptance-packages/' . $slug . '-' . uniqid('', true));
        $zipPath = sys_get_temp_dir() . '/aec-plugin-update-' . $slug . '-' . uniqid('', true) . '.zip';
        File::ensureDirectoryExists(dirname($packageRoot));
        $this->assertTrue(File::copyDirectory($directory, $packageRoot), "{$slug} update fixture could not be staged");
        $manifestData = json_decode($originalManifest, true, 512, JSON_THROW_ON_ERROR);
        $currentVersion = (string) ($manifestData['version'] ?? '1.0.0');
        if (preg_match('/^(.*\\.)(\\d+)$/', $currentVersion, $versionMatch) === 1) {
            $updatedVersion = $versionMatch[1] . ((int) $versionMatch[2] + 1);
        } else {
            $updatedVersion = $currentVersion . '.1';
        }
        $manifestData['version'] = $updatedVersion;
        File::put($packageRoot . '/plugin.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (File::exists($packageRoot . '/checksums.json')) {
            File::delete($packageRoot . '/checksums.json');
        }

        $checksums = [];
        foreach (File::allFiles($packageRoot) as $packageFile) {
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($packageRoot, '', $packageFile->getPathname()), DIRECTORY_SEPARATOR));
            $checksums[$relativePath] = hash_file('sha256', $packageFile->getPathname());
        }
        File::put($packageRoot . '/checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, "{$slug} update ZIP could not be created");
        foreach (File::allFiles($packageRoot) as $packageFile) {
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($packageRoot, '', $packageFile->getPathname()), DIRECTORY_SEPARATOR));
            $this->assertTrue($zip->addFile($packageFile->getPathname(), $slug . '/' . $relativePath), "{$slug} update ZIP could not include {$relativePath}");
        }
        $zip->close();

        try {
            $updatedRecord = $manager->installOrUpdateFromZip($zipPath);
            $this->assertSame($updatedVersion, $updatedRecord->fresh()->version, "{$slug} ZIP update did not apply its newer version");
        } finally {
            @unlink($zipPath);
            File::deleteDirectory($packageRoot);
            // Keep the repository fixture version stable for the remaining tests.
            File::put($directory . '/plugin.json', $originalManifest);
            if (File::exists($directory . '/checksums.json')) {
                File::delete($directory . '/checksums.json');
            }
        }

        $manager->disable($slug);
        $this->assertFalse($record->fresh()->is_enabled, "{$slug} did not disable cleanly");

        $manager->enable($slug);
        $this->assertTrue($record->fresh()->is_enabled, "{$slug} did not re-enable cleanly");

        $this->assertTrue(
            File::exists($directory . '/plugin.json'),
            "{$slug} manifest disappeared during lifecycle operations"
        );

        // Exercise uninstall/reinstall for every package, not only the generic manager fixture.
        $manager->uninstall($slug);
        $this->assertFalse($manager->isInstalled($slug), "{$slug} remained installed after uninstall");
        $this->assertFalse($manager->isEnabled($slug), "{$slug} remained enabled after uninstall");
        $this->assertFileExists($directory . '/plugin.json', "{$slug} package files must remain available after uninstall");

        $manager->install($slug);
        $this->assertTrue($manager->isInstalled($slug), "{$slug} did not reinstall after uninstall");
        $this->assertTrue($manager->isEnabled($slug), "{$slug} did not enable after reinstall");

        $manager->resetSettingsToDefaults($slug);
        $recycled = $manager->deleteToRecycleBin($slug);
        $this->assertFalse($manager->isInstalled($slug), "{$slug} remained installed after recycle-bin delete");
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertDatabaseHas('plugins', ['slug' => $slug, 'is_installed' => false]);

        $restored = $manager->restoreFromRecycleBin($recycled['archive']);
        $this->assertNotNull($restored, "{$slug} did not restore its previous installed state");
        $this->assertTrue($manager->isInstalled($slug), "{$slug} was not installed after restore");
        $this->assertTrue($manager->isEnabled($slug), "{$slug} did not restore its active state");
        $this->assertFileExists($directory . '/plugin.json');
    }
}
