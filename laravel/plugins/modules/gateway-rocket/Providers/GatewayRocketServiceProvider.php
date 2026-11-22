<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayRocket\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GatewayRocketServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-rocket'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
