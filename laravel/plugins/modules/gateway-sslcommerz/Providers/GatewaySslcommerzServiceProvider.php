<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;

class GatewaySslcommerzServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-sslcommerz'; }

    public function register(): void
    {
        $this->app->singleton(SslcommerzGatewayService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
