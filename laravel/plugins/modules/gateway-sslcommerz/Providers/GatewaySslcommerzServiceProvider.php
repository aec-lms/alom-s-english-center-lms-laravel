<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;

class GatewaySslcommerzServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-sslcommerz'; }

    public function register(): void
    {
        $this->app->singleton(SslcommerzGatewayService::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-sslcommerz', 'SSLCOMMERZ', ['store_id', 'store_password'],
                function (array $input): array {
                    if (isset($input['universal_transaction'])) {
                        $url = app(\\Plugins\\Modules\\UniversalPayment\\Services\\StorefrontPaymentGatewayService::class)
                            ->initiateSslcommerz(
                                $input['universal_transaction'],
                                $input['storefront_item'],
                                $input['user'],
                                (string) ($input['payer_contact'] ?? ''),
                                (array) ($input['context'] ?? [])
                            );
                        return ['gateway' => 'gateway-sslcommerz', 'transaction_ref' => $input['universal_transaction']->reference, 'redirect_url' => $url];
                    }
                    $session = app(SslcommerzGatewayService::class)->initiateHostedCheckout($input['invoice']);
                    return ['gateway' => 'gateway-sslcommerz', 'transaction_ref' => $session['transaction']->transaction_ref, 'redirect_url' => $session['hosted_endpoint']];
                }
            );
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
