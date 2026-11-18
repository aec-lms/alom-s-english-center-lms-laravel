<?php

declare(strict_types=1);

namespace Plugins\Modules\SupportCenter\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class SupportCenterServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'support-center';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
