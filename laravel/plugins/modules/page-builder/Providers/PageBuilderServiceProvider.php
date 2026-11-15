<?php

declare(strict_types=1);

namespace Plugins\Modules\PageBuilder\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class PageBuilderServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'page-builder';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
