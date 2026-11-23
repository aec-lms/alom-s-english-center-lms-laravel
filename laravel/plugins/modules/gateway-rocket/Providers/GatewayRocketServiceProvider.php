<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayRocket\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;

class GatewayRocketServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-rocket'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-rocket', 'DBBL Rocket Merchant Gateway', ["api_key","api_secret","username","password"], function (array $input): array {
                throw new \RuntimeException('Rocket checkout requires the official merchant API specification and verified callback adapter.');
            }, false, true);
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
