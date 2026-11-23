<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayNagad\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;

class GatewayNagadServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-nagad'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-nagad', 'Nagad Merchant Gateway', ["api_key","api_secret","username","password"], function (array $input): array {
                throw new \RuntimeException('Nagad checkout requires the merchant-specific official API profile and verified signing/callback adapter.');
            }, false, true);
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
