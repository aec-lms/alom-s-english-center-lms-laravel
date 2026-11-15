<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GatewayStripeServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'gateway-stripe';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
