<?php

declare(strict_types=1);

namespace Plugins\Modules\StorefrontCatalog\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class StorefrontCatalogServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'storefront-catalog';
    }

    protected function pluginPath(string $relative = ''): string
    {
        $base = dirname(__DIR__);
        return $relative !== '' ? $base . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : $base;
    }
}
