<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayBkash\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GatewayBkashServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-bkash'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
