<?php

declare(strict_types=1);

namespace Plugins\Modules\CarouselBuilder\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CarouselBuilderServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'carousel-builder';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
