<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayBkash\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\GatewayBkash\Services\BkashGatewayService;

class GatewayBkashServiceProvider extends BasePluginServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BkashGatewayService::class);
    }

    public function slug(): string { return 'gateway-bkash'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-bkash', 'bKash Merchant Gateway', ['app_key', 'app_secret', 'username', 'password'],
                function (array $input): array {
                    return app(BkashGatewayService::class)->initiateUniversalCheckout(
                        $input['universal_transaction'],
                        $input['storefront_item'],
                        $input['user'],
                        (string) ($input['payer_contact'] ?? '')
                    );
                }, true
            );
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
