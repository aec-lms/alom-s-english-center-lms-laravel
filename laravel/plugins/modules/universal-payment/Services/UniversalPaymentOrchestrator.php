<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Services;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;
use Plugins\Modules\GatewayStripe\Services\StripeGatewayService;
use RuntimeException;

class UniversalPaymentOrchestrator
{
    private const GATEWAYS = [
        'gateway-sslcommerz' => ['label' => 'SSLCOMMERZ', 'credentials' => ['store_id', 'store_password']],
        'gateway-stripe' => ['label' => 'Stripe', 'credentials' => ['publishable_key', 'secret_key', 'webhook_secret']],
        'gateway-paypal' => ['label' => 'PayPal', 'credentials' => ['client_id', 'client_secret', 'webhook_id']],
        'gateway-card' => ['label' => 'Visa / Mastercard Acquirer Adapter', 'credentials' => ['merchant_id', 'api_secret', 'webhook_secret'], 'adapter_required' => true],
        'gateway-bkash' => ['label' => 'bKash Merchant Gateway', 'credentials' => ['app_key', 'app_secret', 'username', 'password'], 'adapter_required' => true],
        'gateway-nagad' => ['label' => 'Nagad Merchant Gateway', 'credentials' => [], 'adapter_required' => true],
        'gateway-rocket' => ['label' => 'DBBL Rocket Merchant Gateway', 'credentials' => [], 'adapter_required' => true],
    ];

    public function __construct(private readonly PluginManager $plugins) {}

    /** @return array<string, array{label:string,available:bool,status:string,missing_credentials:array<int,string>}> */
    public function gatewayStatus(): array
    {
        $enabledMethods = array_values(array_filter(array_map('trim', explode(',', (string) $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,stripe,paypal')))));
        $methodBySlug = [
            'gateway-sslcommerz' => 'sslcommerz',
            'gateway-stripe' => 'stripe',
            'gateway-paypal' => 'paypal',
            'gateway-card' => 'card',
            'gateway-bkash' => 'bkash',
            'gateway-nagad' => 'nagad',
            'gateway-rocket' => 'rocket',
        ];
        $statuses = [];
        foreach (self::GATEWAYS as $slug => $definition) {
            $enabled = $this->plugins->isEnabled($slug);
            $missing = [];
            foreach ($definition['credentials'] as $key) {
                if (($this->plugins->getSetting($slug, $key, '') ?? '') === '') $missing[] = $key;
            }

            $adapterRequired = (bool) ($definition['adapter_required'] ?? false);
            $methodEnabled = in_array($methodBySlug[$slug] ?? '', $enabledMethods, true);
            $available = $methodEnabled && $enabled && $missing === [] && !$adapterRequired;
            $status = !$methodEnabled ? 'disabled_in_universal_payment' : (!$enabled ? 'disabled' : ($missing !== [] ? 'not_configured' : ($adapterRequired ? 'processor_adapter_required' : 'ready')));

            $statuses[$slug] = [
                'label' => $definition['label'],
                'available' => $available,
                'status' => $status,
                'missing_credentials' => $missing,
            ];
        }
        return $statuses;
    }

    public function assertGatewayReady(string $slug): void
    {
        $status = $this->gatewayStatus()[$slug] ?? throw new RuntimeException('Unsupported payment gateway.');
        if (!$status['available']) {
            $reason = match ($status['status']) {
                'disabled_in_universal_payment' => 'Gateway is disabled in Universal Payment settings.',
                'disabled' => 'Gateway plugin is not enabled.',
                'processor_adapter_required' => match ($slug) {
                    'gateway-bkash' => 'bKash merchant credentials are configurable, but hosted checkout is unavailable until its documented Tokenized Checkout adapter and merchant sandbox verification are complete.',
                    'gateway-nagad' => 'Nagad credentials are configurable, but checkout is unavailable until the official merchant API specification, adapter and sandbox verification are supplied.',
                    'gateway-rocket' => 'Rocket credentials are configurable, but checkout is unavailable until the official merchant API specification, adapter and sandbox verification are supplied.',
                    default => 'Visa/Mastercard requires a compatible acquiring-bank or PSP adapter. Use Stripe for supported card processing until that adapter is installed.',
                },
                default => 'Missing credentials: ' . implode(', ', $status['missing_credentials']),
            };
            throw new RuntimeException($status['label'] . ' is unavailable. ' . $reason);
        }
    }

    public function initiate(string $slug, FeeInvoice $invoice, array $input = []): array
    {
        $this->assertGatewayReady($slug);

        return match ($slug) {
            'gateway-sslcommerz' => (function () use ($invoice): array {
                $session = app(SslcommerzGatewayService::class)->initiateHostedCheckout($invoice);
                return ['gateway' => 'gateway-sslcommerz', 'transaction_ref' => $session['transaction']->transaction_ref, 'redirect_url' => $session['hosted_endpoint']];
            })(),
            'gateway-stripe' => (function () use ($invoice, $input): array {
                $result = app(StripeGatewayService::class)->initiateTokenizedCheckout(
                    $invoice,
                    (string) ($input['payment_method_id'] ?? ''),
                    $input['idempotency_key'] ?? null
                );
                return ['gateway' => 'gateway-stripe', 'transaction_ref' => $result['transaction']->transaction_ref, 'payment_intent_id' => $result['payment_intent_id'], 'client_secret' => $result['client_secret']];
            })(),
            'gateway-paypal' => (function () use ($invoice, $input): array {
                $result = app(PaypalGatewayService::class)->createOrder($invoice, $input['idempotency_key'] ?? null);
                return ['gateway' => 'gateway-paypal', 'transaction_ref' => $result['transaction']->transaction_ref, 'paypal_order_id' => $result['paypal_order_id'], 'redirect_url' => $result['approval_url']];
            })(),
            default => throw new RuntimeException('This payment gateway has no active processor adapter.'),
        };
    }
}
