<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\PaymentManagement\Services\InvoicePaymentSessionReservationService;
use RuntimeException;

class PaypalGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
        private readonly InvoicePaymentSessionReservationService $reservations,
    ) {}

    private function apiBase(): string
    {
        return $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function accessToken(string $apiBase): string
    {
        $clientId = (string) $this->plugins->getSetting('gateway-paypal', 'client_id', '');
        $secret = (string) $this->plugins->getSetting('gateway-paypal', 'client_secret', '');
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('PayPal Client ID and Client Secret are not configured in Admin → Plugin Center → PayPal → Configure.');
        }

        $response = Http::asForm()->withBasicAuth($clientId, $secret)
            ->timeout(20)->post($apiBase . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$response->successful() || !$response->json('access_token')) {
            throw new RuntimeException('PayPal OAuth authentication failed. Check Client ID, Secret and Sandbox/Live mode.');
        }
        return (string) $response->json('access_token');
    }

    public function createOrder(FeeInvoice $invoice, ?string $idempotencyKey = null): array
    {
        $sandbox = $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1';
        $apiBase = $this->apiBase();
        $invoiceNumber = (string) $invoice->invoice_number;
        $description = 'AEC LMS invoice ' . $invoiceNumber;
        $brandName = "Alom's English Center";
        $userAction = 'PAY_NOW';
        $returnUrl = url('/payments/paypal/return');
        $cancelUrl = url('/payments/paypal/cancel');

        $reservation = $this->reservations->reserve(
            $invoice,
            'gateway-paypal',
            'PYPL',
            [
                'fingerprint_parameters' => [
                    'provider_api_base' => $apiBase,
                    'intent' => 'CAPTURE',
                    'reference_id' => $invoiceNumber,
                    'description' => $description,
                    'brand_name' => $brandName,
                    'user_action' => $userAction,
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ],
            $idempotencyKey,
        );
        $invoice = $reservation['invoice'];
        $tx = $reservation['transaction'];
        $remaining = $reservation['remaining'];

        if ($reservation['reused']) {
            if (in_array($tx->status, ['failed', 'refunded', 'settled'], true)) {
                return [
                    'transaction' => $tx,
                    'paypal_order_id' => (string) ($tx->metadata['paypal_order_id'] ?? ''),
                    'approval_url' => '',
                ];
            }

            $approvalUrl = (string) ($tx->metadata['approval_url'] ?? '');
            $approvalHost = strtolower((string) parse_url($approvalUrl, PHP_URL_HOST));
            $allowedApprovalHosts = $sandbox
                ? ['www.sandbox.paypal.com', 'sandbox.paypal.com']
                : ['www.paypal.com', 'paypal.com'];
            if ($approvalUrl === ''
                || strtolower((string) parse_url($approvalUrl, PHP_URL_SCHEME)) !== 'https'
                || !in_array($approvalHost, $allowedApprovalHosts, true)) {
                throw new RuntimeException('A PayPal order is already reserved but its trusted approval URL is unavailable; reconciliation is required.');
            }

            return [
                'transaction' => $tx,
                'paypal_order_id' => (string) ($tx->metadata['paypal_order_id'] ?? ''),
                'approval_url' => $approvalUrl,
            ];
        }

        $response = null;
        $orderRequestStarted = false;
        try {
            $token = $this->accessToken($apiBase);
            $orderRequestStarted = true;
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(!empty($reservation['provider_idempotency_key']) ? ['PayPal-Request-Id' => $reservation['provider_idempotency_key']] : [])
                ->timeout(25)
                ->post($apiBase . '/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => $invoiceNumber,
                        'custom_id' => $tx->transaction_ref,
                        'description' => $description,
                        'amount' => [
                            'currency_code' => strtoupper($invoice->currency),
                            'value' => number_format($remaining, 2, '.', ''),
                        ],
                    ]],
                    'application_context' => [
                        'brand_name' => $brandName,
                        'user_action' => $userAction,
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ]);

            if (!$response->successful()) {
                if ($response->clientError()) {
                    $this->reservations->markRejected($tx, 'provider_http_4xx');
                } else {
                    $this->reservations->markUnknown($tx, 'provider_http_5xx');
                }
                throw new RuntimeException((string) $response->json('message', 'PayPal Orders API request failed.'));
            }

            $order = $response->json();
            $approvalUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
            $approvalHost = strtolower((string) parse_url((string) $approvalUrl, PHP_URL_HOST));
            $sandbox = $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1';
            $allowedApprovalHosts = $sandbox
                ? ['www.sandbox.paypal.com', 'sandbox.paypal.com']
                : ['www.paypal.com', 'paypal.com'];
            if (empty($order['id']) || !$approvalUrl
                || strtolower((string) parse_url((string) $approvalUrl, PHP_URL_SCHEME)) !== 'https'
                || !in_array($approvalHost, $allowedApprovalHosts, true)) {
                $this->reservations->markUnknown($tx, 'invalid_success_response');
                throw new RuntimeException('PayPal did not return a valid trusted approval URL; reservation remains held for reconciliation.');
            }

            $tx = $this->reservations->markCreated($tx, [
                'paypal_order_id' => (string) $order['id'],
                'approval_url' => (string) $approvalUrl,
                'paypal_status' => (string) ($order['status'] ?? 'CREATED'),
            ], 'initiated', (string) $order['id']);

            return ['transaction' => $tx, 'paypal_order_id' => (string) $order['id'], 'approval_url' => (string) $approvalUrl];
        } catch (\Throwable $e) {
            if ($response === null && !$orderRequestStarted) {
                $this->reservations->markRejected($tx, 'oauth_token_request_failed');
            } elseif ($response === null || $response->serverError()) {
                $this->reservations->markUnknown($tx, 'provider_http_5xx');
            } elseif ($response->successful()) {
                $this->reservations->markUnknown($tx, 'post_provider_processing_error');
            }
            if ($e instanceof RuntimeException) throw $e;
            throw new RuntimeException('Unable to create PayPal checkout order; reservation remains held for reconciliation.', 0, $e);
        }
    }

    public function captureOrder(string $transactionRef): PaymentTransaction
    {
        $tx = PaymentTransaction::query()->where('transaction_ref', $transactionRef)
            ->where('gateway_slug', 'gateway-paypal')->firstOrFail();
        if ($tx->status === 'settled') return $tx;
        $orderId = (string) ($tx->metadata['paypal_order_id'] ?? '');
        if ($orderId === '') throw new RuntimeException('PayPal order reference is missing.');

        $response = Http::withToken($this->accessToken($this->apiBase()))->acceptJson()
            ->withHeaders(['PayPal-Request-Id' => 'capture-' . $tx->transaction_ref])
            ->timeout(25)->post($this->apiBase() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');

        if (!$response->successful()) {
            throw new RuntimeException((string) $response->json('message', 'PayPal order capture failed.'));
        }
        $captures = collect($response->json('purchase_units.0.payments.captures', []));
        if ($captures->count() !== 1) {
            throw new RuntimeException('PayPal orders must contain exactly one capture until each capture has its own payment ledger transaction.');
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

        $verification = Http::withToken($this->accessToken($this->apiBase()))->acceptJson()->timeout(20)
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
        $token = $this->accessToken($this->apiBase());
        $response = Http::withToken($token)->acceptJson()->timeout(15)->get($this->apiBase() . '/v1/identity/oauth2/userinfo?schema=paypalv1.1');
        if (!$response->successful()) {
            // OAuth itself is the authoritative credential check; some accounts do not expose userinfo.
            return ['connected' => true, 'mode' => $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1' ? 'sandbox' : 'live'];
        }
        return ['connected' => true, 'mode' => $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1' ? 'sandbox' : 'live'];
    }
}
