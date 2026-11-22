<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayNagad\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GatewayNagadServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-nagad'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
