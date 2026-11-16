<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Core\Plugins\PluginManifest;
use App\Models\PluginRecord;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

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

        $manager->disable($slug);
        $this->assertFalse($record->fresh()->is_enabled, "{$slug} did not disable cleanly");

        $manager->enable($slug);
        $this->assertTrue($record->fresh()->is_enabled, "{$slug} did not re-enable cleanly");

        $this->assertTrue(
            File::exists($directory . '/plugin.json'),
            "{$slug} manifest disappeared during lifecycle operations"
        );
    }
}
