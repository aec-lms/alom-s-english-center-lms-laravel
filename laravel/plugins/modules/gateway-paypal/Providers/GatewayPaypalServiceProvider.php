<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GatewayPaypalServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'gateway-paypal';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
