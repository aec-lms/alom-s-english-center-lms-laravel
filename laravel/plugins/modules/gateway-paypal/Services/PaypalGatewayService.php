<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;

class PaypalGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
    ) {}

    private function apiBase(): string
    {
        return $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function accessToken(): string
    {
        $clientId = (string) $this->plugins->getSetting('gateway-paypal', 'client_id', '');
        $secret = (string) $this->plugins->getSetting('gateway-paypal', 'client_secret', '');
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('PayPal Client ID and Client Secret are not configured in Admin → Plugin Center → PayPal → Configure.');
        }

        $response = Http::asForm()->withBasicAuth($clientId, $secret)
            ->timeout(20)->post($this->apiBase() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$response->successful() || !$response->json('access_token')) {
            throw new RuntimeException('PayPal OAuth authentication failed. Check Client ID, Secret and Sandbox/Live mode.');
        }
        return (string) $response->json('access_token');
    }

    public function createOrder(FeeInvoice $invoice, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey) {
            $existing = PaymentTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('gateway_slug', 'gateway-paypal')
                ->where('metadata->idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return [
                    'transaction' => $existing,
                    'paypal_order_id' => (string) ($existing->metadata['paypal_order_id'] ?? ''),
                    'approval_url' => (string) ($existing->metadata['approval_url'] ?? ''),
                ];
            }
        }

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0) throw new RuntimeException('Invoice is already fully settled.');

        $tx = PaymentTransaction::query()->create([
            'transaction_ref' => 'PYPL-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'gateway-paypal',
            'amount' => $remaining,
            'currency' => strtoupper($invoice->currency),
            'status' => 'initiated',
            'metadata' => ['idempotency_key' => $idempotencyKey],
        ]);

        try {
            $token = $this->accessToken();
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders($idempotencyKey ? ['PayPal-Request-Id' => $idempotencyKey] : [])
                ->timeout(25)
                ->post($this->apiBase() . '/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => (string) $invoice->invoice_number,
                        'custom_id' => $tx->transaction_ref,
                        'description' => 'AEC LMS invoice ' . $invoice->invoice_number,
                        'amount' => [
                            'currency_code' => strtoupper($invoice->currency),
                            'value' => number_format($remaining, 2, '.', ''),
                        ],
                    ]],
                    'application_context' => [
                        'brand_name' => "Alom's English Center",
                        'user_action' => 'PAY_NOW',
                        'return_url' => url('/payments/paypal/return'),
                        'cancel_url' => url('/payments/paypal/cancel'),
                    ],
                ]);

            if (!$response->successful()) {
                throw new RuntimeException((string) $response->json('message', 'PayPal Orders API request failed.'));
            }

            $order = $response->json();
            $approvalUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
            if (empty($order['id']) || !$approvalUrl || !str_starts_with((string) $approvalUrl, 'https://')) {
                throw new RuntimeException('PayPal did not return a valid order ID and approval URL.');
            }

            $tx->update([
                'external_reference' => (string) $order['id'],
                'metadata' => array_merge($tx->metadata ?? [], [
                    'paypal_order_id' => (string) $order['id'],
                    'approval_url' => (string) $approvalUrl,
                    'paypal_status' => (string) ($order['status'] ?? 'CREATED'),
                ]),
            ]);

            return ['transaction' => $tx->fresh(), 'paypal_order_id' => (string) $order['id'], 'approval_url' => (string) $approvalUrl];
        } catch (\Throwable $e) {
            $tx->update(['status' => 'failed']);
            if ($e instanceof RuntimeException) throw $e;
            throw new RuntimeException('Unable to create PayPal checkout order.', 0, $e);
        }
    }

    public function captureOrder(string $transactionRef): PaymentTransaction
    {
        $tx = PaymentTransaction::query()->where('transaction_ref', $transactionRef)
            ->where('gateway_slug', 'gateway-paypal')->firstOrFail();
        if ($tx->status === 'settled') return $tx;
        $orderId = (string) ($tx->metadata['paypal_order_id'] ?? '');
        if ($orderId === '') throw new RuntimeException('PayPal order reference is missing.');

        $response = Http::withToken($this->accessToken())->acceptJson()
            ->withHeaders(['PayPal-Request-Id' => 'capture-' . $tx->transaction_ref])
            ->timeout(25)->post($this->apiBase() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');

        if (!$response->successful()) {
            throw new RuntimeException((string) $response->json('message', 'PayPal order capture failed.'));
        }
        $captures = collect($response->json('purchase_units.0.payments.captures', []));
        if ($captures->count() !== 1) {
            throw new RuntimeException('PayPal multi-capture orders are blocked until each capture has its own payment ledger transaction.');
        }
        $capture = $captures->first();
        if (!$capture || ($capture['status'] ?? '') !== 'COMPLETED') {
            throw new RuntimeException('PayPal has not confirmed a completed capture.');
        }

        $amount = $capture['amount'] ?? [];
        if (abs((float) ($amount['value'] ?? 0) - (float) $tx->amount) > 0.01
            || strtoupper((string) ($amount['currency_code'] ?? '')) !== strtoupper((string) $tx->currency)) {
            throw new RuntimeException('PayPal capture amount or currency does not match the invoice.');
        }

        return $this->settlement->settleTransaction($tx, (string) $capture['id'], [
            'paypal_capture_status' => 'COMPLETED',
            'paypal_order_id' => $orderId,
        ]);
    }

    public function verifyAndSettleWebhook(array $headers, array $event): PaymentTransaction
    {
        $webhookId = (string) $this->plugins->getSetting('gateway-paypal', 'webhook_id', '');
        if ($webhookId === '') throw new RuntimeException('PayPal Webhook ID is not configured.');

        $verification = Http::withToken($this->accessToken())->acceptJson()->timeout(20)
            ->post($this->apiBase() . '/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $headers['auth_algo'] ?? '',
                'cert_url' => $headers['cert_url'] ?? '',
                'transmission_id' => $headers['transmission_id'] ?? '',
                'transmission_sig' => $headers['transmission_sig'] ?? '',
                'transmission_time' => $headers['transmission_time'] ?? '',
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ]);
        if (!$verification->successful() || $verification->json('verification_status') !== 'SUCCESS') {
            throw new RuntimeException('PayPal webhook signature verification failed.');
        }

        if (($event['event_type'] ?? '') !== 'PAYMENT.CAPTURE.COMPLETED') {
            throw new RuntimeException('PayPal webhook is not a completed capture event.');
        }

        $capture = $event['resource'] ?? [];
        $transactionRef = (string) ($capture['custom_id'] ?? '');
        if ($transactionRef === '') {
            $orderId = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
            $transactionRef = (string) PaymentTransaction::query()->where('external_reference', $orderId)->where('gateway_slug', 'gateway-paypal')->value('transaction_ref');
        }
        if ($transactionRef === '') throw new RuntimeException('PayPal capture is missing the LMS transaction reference.');

        $tx = PaymentTransaction::query()->where('transaction_ref', $transactionRef)
            ->where('gateway_slug', 'gateway-paypal')->firstOrFail();
        if ((string) ($capture['id'] ?? '') === '' || (string) ($capture['status'] ?? '') !== 'COMPLETED') {
            throw new RuntimeException('PayPal capture is not completed.');
        }
        if ($tx->status === 'settled' && (string) $tx->external_reference !== (string) $capture['id']) {
            throw new RuntimeException('PayPal webhook capture does not match the already-settled capture reference.');
        }
        $amount = $capture['amount'] ?? [];
        if (abs((float) ($amount['value'] ?? 0) - (float) $tx->amount) > 0.01
            || strtoupper((string) ($amount['currency_code'] ?? '')) !== strtoupper((string) $tx->currency)) {
            throw new RuntimeException('PayPal webhook amount or currency does not match the invoice.');
        }

        return $this->settlement->settleTransaction($tx, (string) $capture['id'], [
            'paypal_webhook_verified' => true,
            'paypal_event_id' => (string) ($event['id'] ?? ''),
        ]);
    }

    public function testCredentials(): array
    {
        $token = $this->accessToken();
        $response = Http::withToken($token)->acceptJson()->timeout(15)->get($this->apiBase() . '/v1/identity/oauth2/userinfo?schema=paypalv1.1');
        if (!$response->successful()) {
            // OAuth itself is the authoritative credential check; some accounts do not expose userinfo.
            return ['connected' => true, 'mode' => $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1' ? 'sandbox' : 'live'];
        }
        return ['connected' => true, 'mode' => $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1' ? 'sandbox' : 'live'];
    }
}
