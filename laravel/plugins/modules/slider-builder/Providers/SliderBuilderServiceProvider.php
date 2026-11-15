<?php

declare(strict_types=1);

namespace Plugins\Modules\SliderBuilder\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class SliderBuilderServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'slider-builder';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
