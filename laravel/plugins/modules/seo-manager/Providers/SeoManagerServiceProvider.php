<?php

declare(strict_types=1);

namespace Plugins\Modules\SeoManager\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class SeoManagerServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'seo-manager'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
