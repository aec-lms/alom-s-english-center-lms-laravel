<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginCategoryRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginCategoryRegistryTest extends TestCase
{
    public function test_all_sixty_current_plugin_slugs_have_one_canonical_category(): void
    {
        $slugs = PluginCategoryRegistry::knownSlugs();

        $this->assertCount(63, $slugs);
        $this->assertCount(63, array_unique($slugs));

        $manifestSlugs = [];
        foreach (File::directories(base_path('plugins/modules')) as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!File::exists($manifestPath)) continue;
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifestSlugs[] = (string) ($manifest['slug'] ?? basename($directory));
        }
        sort($manifestSlugs);
        $registeredSlugs = $slugs;
        sort($registeredSlugs);
        $this->assertSame($manifestSlugs, $registeredSlugs, 'Every current plugin manifest must have exactly one canonical category.');

        foreach ($slugs as $slug) {
            $key = PluginCategoryRegistry::keyFor($slug);
            $this->assertArrayHasKey($key, PluginCategoryRegistry::labels());
            $this->assertNotSame('other', $key, "Known plugin {$slug} has no canonical category.");
        }
    }

    public function test_registry_matches_every_current_plugin_manifest_slug(): void
    {
        $manifestFiles = glob(base_path('plugins/modules/*/plugin.json')) ?: [];
        $manifestSlugs = [];

        foreach ($manifestFiles as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($manifest);
            $this->assertIsString($manifest['slug'] ?? null);
            $manifestSlugs[] = $manifest['slug'];
        }

        $registeredSlugs = PluginCategoryRegistry::knownSlugs();
        sort($manifestSlugs);
        sort($registeredSlugs);

        $this->assertSame($manifestSlugs, $registeredSlugs, 'Every plugin manifest must be classified exactly once.');
    }

    public function test_legacy_categories_and_unknown_future_plugins_fall_back_safely(): void
    {
        $this->assertSame('academic-learning', PluginCategoryRegistry::keyFor('future-academic-plugin', 'education'));
        $this->assertSame('finance-commerce', PluginCategoryRegistry::keyFor('future-finance-plugin', 'payment-gateway'));
        $this->assertSame('communication-support', PluginCategoryRegistry::keyFor('future-messaging-plugin', 'communications'));
        $this->assertSame('content-cms', PluginCategoryRegistry::keyFor('future-site-plugin', 'website'));
        $this->assertSame('platform-branding', PluginCategoryRegistry::keyFor('future-theme-plugin', 'appearance'));
        $this->assertSame('other', PluginCategoryRegistry::keyFor('future-unknown-plugin', 'unclassified'));
        $this->assertSame('Other', PluginCategoryRegistry::labelFor('future-unknown-plugin', 'unclassified'));
    }

    public function test_plugin_center_groups_catalog_cards_by_canonical_category(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)->get('/admin/plugins')->assertOk();

        $response->assertSee('Academic & Learning')
            ->assertSee('Student & People Lifecycle')
            ->assertSee('Finance & Commerce')
            ->assertSee('Content & Website CMS')
            ->assertSee('Platform Branding')
            ->assertSee('Course & Academic Management')
            ->assertSee('Universal Payment Gateway Plugin');
    }

    public function test_category_resolution_does_not_rewrite_manifest_category_values(): void
    {
        $this->assertSame('academic-learning', PluginCategoryRegistry::keyFor('course-management', 'academic'));
        $this->assertSame('finance-commerce', PluginCategoryRegistry::keyFor('universal-payment', 'finance'));
        $this->assertSame('Academic & Learning', PluginCategoryRegistry::labelFor('course-management', 'academic'));
        $this->assertSame('Finance & Commerce', PluginCategoryRegistry::labelFor('universal-payment', 'finance'));
    }
}
