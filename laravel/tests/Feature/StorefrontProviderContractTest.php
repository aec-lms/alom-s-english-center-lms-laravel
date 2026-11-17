<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Contracts\PluginInterface;
use App\Core\Plugins\PluginManifest;
use Tests\TestCase;

class StorefrontProviderContractTest extends TestCase
{
    public function test_storefront_manifest_resolves_a_real_plugin_runtime_provider(): void
    {
        $manifest = PluginManifest::fromDirectory(base_path('plugins/modules/storefront-catalog'));

        $this->assertSame(
            'Plugins\\Modules\\StorefrontCatalog\\Providers\\StorefrontCatalogServiceProvider',
            $manifest->providerClass
        );
        $this->assertTrue(class_exists($manifest->providerClass));
        $this->assertTrue(is_subclass_of($manifest->providerClass, PluginInterface::class));
    }
}
