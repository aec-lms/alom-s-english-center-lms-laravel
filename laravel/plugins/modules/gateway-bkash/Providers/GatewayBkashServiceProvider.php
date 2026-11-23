<?php

declare(strict_types=1);

namespace Plugins\\Modules\\GatewayBkash\\Providers;

use App\\Core\\Payments\\PaymentGatewayRegistry;
use App\\Core\\Plugins\\BasePluginServiceProvider;

class GatewayBkashServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-bkash'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-bkash', 'bKash Merchant Gateway', ["app_key","app_secret","username","password"], function (array $input): array {
                throw new \\RuntimeException('bKash token authentication is available, but checkout/callback settlement is not enabled until the complete merchant Tokenized Checkout flow is implemented and UAT verified.');
            }, false);
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
