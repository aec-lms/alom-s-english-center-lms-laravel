<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Core\Plugins\PluginManifest;
use App\Models\PluginRecord;
use App\Models\PluginSetting;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;
use RuntimeException;

class AllPluginsIndependentAcceptanceTest extends TestCase
{
    public static function pluginSlugs(): array
    {
        // Discover every module manifest so newly added plugins are automatically
        // included in the full lifecycle acceptance suite without editing a list.
        $modulesPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'modules';
        $manifests = glob($modulesPath . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'plugin.json') ?: [];
        $slugs = [];

        foreach ($manifests as $manifestPath) {
            $slug = basename(dirname($manifestPath));
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $slug) === 1) {
                $slugs[] = [$slug];
            }
        }

        usort($slugs, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return $slugs;
    }

    public function test_legacy_plugin_recycle_bin_rejects_a_symlinked_package_source(): void
    {
        $modulesRoot = realpath((string) config('plugins.modules_path'));
        $this->assertNotFalse($modulesRoot);

        $outside = storage_path('framework/testing/legacy-plugin-outside-' . bin2hex(random_bytes(5)));
        $link = $modulesRoot . DIRECTORY_SEPARATOR . 'legacy-symlink-fixture';
        File::ensureDirectoryExists($outside);
        File::put($outside . DIRECTORY_SEPARATOR . 'outside.txt', 'must remain untouched');

        if (!@symlink($outside, $link)) {
            File::deleteDirectory($outside);
            $this->markTestSkipped('The test environment does not permit creating symbolic links.');
        }

        try {
            try {
                app(PluginManager::class)->deleteToRecycleBin('legacy-symlink-fixture');
                $this->fail('A symlinked plugin source must never be moved into the recycle bin.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('outside the registered modules directory', $exception->getMessage());
            }

            $this->assertTrue(is_link($link), 'The symlink itself must remain untouched.');
            $this->assertFileExists($outside . DIRECTORY_SEPARATOR . 'outside.txt');
            $this->assertSame('must remain untouched', File::get($outside . DIRECTORY_SEPARATOR . 'outside.txt'));
        } finally {
            @unlink($link);
            File::deleteDirectory($outside);
        }
    }

    #[DataProvider('pluginSlugs')]
    public function test_each_plugin_installs_with_only_its_declared_required_siblings(string $slug): void
    {
        $this->markSystemInstalled();

        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        $directory = base_path('plugins/modules/' . $slug);
        $this->assertDirectoryExists($directory, "Missing plugin directory: {$slug}");
        $this->assertFileExists($directory . '/plugin.json', "Missing manifest: {$slug}");

        $manifest = PluginManifest::fromDirectory($directory);
        $this->assertSame($slug, $manifest->slug);
        foreach ($manifest->dependencies as $dependencySlug) {
            $dependency = $manager->discoverManifests()[$dependencySlug] ?? null;
            $this->assertNotNull($dependency, "{$slug} declares missing required dependency {$dependencySlug}");
            if (!PluginRecord::query()->where('slug', $dependencySlug)->where('is_enabled', true)->exists()) {
                $manager->install($dependencySlug);
            }
        }

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

        // The ZIP update fixture must follow the production trusted-signature path.
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP sodium extension is required for signed plugin packages.');
        }
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $checksumsHash = hash_file('sha256', $packageRoot . '/checksums.json');
        $manifestHash = hash_file('sha256', $packageRoot . '/plugin.json');
        $signedMessage = implode("\n", [
            'AEC-LMS-PLUGIN-SIGNATURE-V1',
            $slug,
            $updatedVersion,
            $checksumsHash,
            $manifestHash,
        ]);
        $signaturePath = sys_get_temp_dir() . '/aec-plugin-signature-' . bin2hex(random_bytes(8)) . '.json';
        File::put($signaturePath, json_encode([
            'key_id' => 'acceptance-test',
            'signature' => base64_encode(sodium_crypto_sign_detached($signedMessage, $secretKey)),
        ], JSON_THROW_ON_ERROR));
        config(['plugins.signature_keys' => ['acceptance-test' => base64_encode($publicKey)]]);

        try {
            $updatedRecord = $manager->installOrUpdateFromZip($zipPath, null, $signaturePath);
            $this->assertSame($updatedVersion, $updatedRecord->fresh()->version, "{$slug} ZIP update did not apply its newer version");
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
            File::deleteDirectory($packageRoot);
            // Keep the repository fixture version stable for the remaining tests.
            File::put($directory . '/plugin.json', $originalManifest);
            if (File::exists($directory . '/checksums.json')) {
                File::delete($directory . '/checksums.json');
            }
            // The fixture restores the original first-party source tree, so clear
            // the temporary signature metadata written by the ZIP update probe.
            PluginRecord::query()->where('slug', $slug)->update([
                'signature_key_id' => null,
                'signature_algorithm' => null,
                'signature_checksums_sha256' => null,
                'signature_verified_at' => null,
            ]);
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

        // Verify reset removes non-default configuration and reapplies every declared default.
        PluginSetting::query()->updateOrCreate(
            ['plugin_slug' => $slug, 'setting_key' => 'acceptance_probe'],
            ['setting_value' => 'non-default-value', 'is_encrypted' => false]
        );
        $manager->resetSettingsToDefaults($slug);
        $this->assertNull($manager->getSetting($slug, 'acceptance_probe'), "{$slug} retained a custom setting after default reset");
        foreach ($manifest->settingsSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $default = $field['default'] ?? '';
            $expected = is_scalar($default) ? (string) $default : json_encode($default, JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $manager->getSetting($slug, $key), "{$slug} did not restore the declared default for {$key}");
        }

        $recycled = $manager->deleteToRecycleBin($slug);
        $this->assertFalse($manager->isInstalled($slug), "{$slug} remained installed after recycle-bin delete");
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertDatabaseHas('plugins', ['slug' => $slug, 'is_installed' => false]);

        $restored = $manager->restoreFromRecycleBin($recycled['archive']);
        $this->assertNotNull($restored, "{$slug} did not restore its previous installed state");
        $this->assertTrue($manager->isInstalled($slug), "{$slug} was not installed after restore");
        $this->assertTrue($manager->isEnabled($slug), "{$slug} did not restore its active state");
        $this->assertFileExists($directory . '/plugin.json');

        // Verify the irreversible Recycle Bin action for every plugin, while
        // retaining the manager's private recovery snapshot for test isolation.
        $secondRecycle = $manager->deleteToRecycleBin($slug);
        $archiveSource = storage_path('app/plugin-recycle-bin/' . $secondRecycle['archive']);
        $privateBackup = storage_path('app/plugin-permanent-delete-backups/' . $secondRecycle['archive']);
        File::ensureDirectoryExists(dirname($privateBackup));
        $this->assertTrue(File::copyDirectory($archiveSource, $privateBackup), "{$slug} retry fixture could not create an existing recovery snapshot");
        $manager->permanentlyDeleteFromRecycleBin($secondRecycle['archive']);
        $this->assertDirectoryExists($privateBackup, "{$slug} permanent delete must create a verified recovery backup");
        $this->assertSame([], $manager->recycledPlugins(), "{$slug} archive must leave the Recycle Bin after permanent delete");
        $this->assertTrue(File::copyDirectory($privateBackup, $directory), "{$slug} source fixture could not be restored from its private backup");
        $this->assertFileExists($directory . '/plugin.json');
    }
}
