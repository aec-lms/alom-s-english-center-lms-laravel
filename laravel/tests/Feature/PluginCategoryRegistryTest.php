<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Core\\Plugins\\PluginCategoryRegistry;
use Tests\\TestCase;

class PluginCategoryRegistryTest extends TestCase
{
    public function test_all_sixty_current_plugin_slugs_have_one_canonical_category(): void
    {
        $slugs = PluginCategoryRegistry::knownSlugs();

        $this->assertCount(60, $slugs);
        $this->assertCount(60, array_unique($slugs));

        foreach ($slugs as $slug) {
            $key = PluginCategoryRegistry::keyFor($slug);
            $this->assertArrayHasKey($key, PluginCategoryRegistry::labels());
            $this->assertNotSame('other', $key, "Known plugin {$slug} has no canonical category.");
        }
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

    public function test_category_resolution_does_not_rewrite_manifest_category_values(): void
    {
        $this->assertSame('academic-learning', PluginCategoryRegistry::keyFor('course-management', 'academic'));
        $this->assertSame('finance-commerce', PluginCategoryRegistry::keyFor('universal-payment', 'finance'));
        $this->assertSame('Academic & Learning', PluginCategoryRegistry::labelFor('course-management', 'academic'));
        $this->assertSame('Finance & Commerce', PluginCategoryRegistry::labelFor('universal-payment', 'finance'));
    }
}
