<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;

class GatewayPaypalServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-paypal'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-paypal', 'PayPal', ['client_id', 'client_secret', 'webhook_id'],
                function (array $input): array {
                    $result = app(PaypalGatewayService::class)->createOrder(
                        $input['invoice'],
                        $input['idempotency_key'] ?? null,
                        isset($input['amount']) ? (float) $input['amount'] : null
                    );
                    return ['gateway' => 'gateway-paypal', 'transaction_ref' => $result['transaction']->transaction_ref, 'paypal_order_id' => $result['paypal_order_id'], 'redirect_url' => $result['approval_url']];
                }
            );
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
