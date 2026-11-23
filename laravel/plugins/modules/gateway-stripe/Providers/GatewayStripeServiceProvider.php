<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\GatewayStripe\Services\StripeGatewayService;

class GatewayStripeServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-stripe'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-stripe', 'Stripe', ['publishable_key', 'secret_key', 'webhook_secret'],
                function (array $input): array {
                    $result = app(StripeGatewayService::class)->initiateTokenizedCheckout(
                        $input['invoice'],
                        (string) ($input['payment_method_id'] ?? ''),
                        $input['idempotency_key'] ?? null,
                        isset($input['amount']) ? (float) $input['amount'] : null
                    );
                    return ['gateway' => 'gateway-stripe', 'transaction_ref' => $result['transaction']->transaction_ref, 'payment_intent_id' => $result['payment_intent_id'], 'client_secret' => $result['client_secret']];
                }
            );
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
