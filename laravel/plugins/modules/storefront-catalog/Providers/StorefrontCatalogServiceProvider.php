<?php

declare(strict_types=1);

namespace Plugins\Modules\StorefrontCatalog\Providers;

use Illuminate\Support\ServiceProvider;

class StorefrontCatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'storefront-catalog');
    }
}
