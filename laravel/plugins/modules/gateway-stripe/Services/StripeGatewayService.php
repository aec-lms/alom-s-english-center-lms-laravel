<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\PaymentManagement\Services\InvoicePaymentSessionReservationService;
use RuntimeException;

class StripeGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
        private readonly InvoicePaymentSessionReservationService $reservations,
    ) {}

    public function initiateTokenizedCheckout(FeeInvoice $invoice, string $paymentToken, ?string $idempotencyKey = null): array
    {
        $secretKey = (string) $this->plugins->getSetting('gateway-stripe', 'secret_key', '');
        if ($secretKey === '') {
            throw new RuntimeException('Stripe secret key is not configured. Add it in Admin → Plugin Center → Stripe → Configure.');
        }
        if (!str_starts_with($secretKey, 'sk_test_') && !str_starts_with($secretKey, 'sk_live_')) {
            throw new RuntimeException('Stripe secret key format is invalid.');
        }
        if (!str_starts_with($paymentToken, 'pm_')) {
            throw new RuntimeException('Stripe requires a PaymentMethod ID created by Stripe.js (pm_...). Raw card details are never accepted.');
        }

        $providerMode = str_starts_with($secretKey, 'sk_live_') ? 'live' : 'test';
        $description = 'AEC LMS invoice ' . $invoice->invoice_number;
        $returnUrl = url('/payments/stripe/return');
        $reservation = $this->reservations->reserve(
            $invoice,
            'gateway-stripe',
            'STRP',
            [
                'fingerprint_parameters' => [
                    'payment_method_id' => $paymentToken,
                    'confirm' => 'true',
                    'automatic_payment_methods_enabled' => 'true',
                    'provider_mode' => $providerMode,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'description' => $description,
                    'return_url' => $returnUrl,
                ],
            ],
            $idempotencyKey,
        );
        $invoice = $reservation['invoice'];
        $tx = $reservation['transaction'];
        $remaining = $reservation['remaining'];

        if ($reservation['reused']) {
            if (in_array($tx->status, ['initiated', 'pending', 'risk_review', 'processing', 'authorized'], true)
                && ($tx->metadata['session_outcome'] ?? '') !== 'created') {
                throw new RuntimeException('A Stripe session reservation already exists with an unknown provider outcome; reconciliation is required before retrying.');
            }

            return [
                'transaction' => $tx,
                'client_secret' => $tx->metadata['client_secret'] ?? null,
                'payment_intent_id' => $tx->external_reference,
            ];
        }

        $response = null;
        try {
            $amountMinor = (int) round($remaining * 100);
            $response = Http::asForm()
                ->withBasicAuth($secretKey, '')
                ->withHeaders(!empty($reservation['provider_idempotency_key']) ? ['Idempotency-Key' => $reservation['provider_idempotency_key']] : [])
                ->timeout(25)
                ->post('https://api.stripe.com/v1/payment_intents', [
                    'amount' => $amountMinor,
                    'currency' => strtolower($invoice->currency),
                    'payment_method' => $paymentToken,
                    'confirm' => 'true',
                    'automatic_payment_methods[enabled]' => 'true',
                    'metadata[transaction_ref]' => $tx->transaction_ref,
                    'metadata[invoice_number]' => (string) $invoice->invoice_number,
                    'description' => $description,
                    'return_url' => $returnUrl,
                ]);

            if (!$response->successful()) {
                if ($response->clientError()) {
                    $this->reservations->markRejected($tx, 'provider_http_4xx');
                } else {
                    $this->reservations->markUnknown($tx, 'provider_http_5xx');
                }
                $message = (string) $response->json('error.message', 'Stripe PaymentIntent creation failed.');
                throw new RuntimeException($message);
            }

            $intent = $response->json();
            if (!is_array($intent) || empty($intent['id']) || !str_starts_with((string) $intent['id'], 'pi_') || empty($intent['client_secret'])) {
                $this->reservations->markUnknown($tx, 'invalid_success_response');
                throw new RuntimeException('Stripe returned an invalid PaymentIntent response; the reservation remains held for reconciliation.');
            }

            $status = (string) ($intent['status'] ?? 'requires_payment_method');
            if ($status !== 'succeeded'
                && !in_array($status, ['requires_action', 'requires_confirmation', 'processing', 'requires_capture'], true)) {
                $this->reservations->markUnknown($tx, 'unexpected_payment_intent_status');
                throw new RuntimeException('Stripe payment could not be confirmed. Status: ' . $status . '. Reservation remains held for reconciliation.');
            }

            // A PaymentIntent client_secret is a bearer credential. Return it
            // only to the authenticated checkout response; never persist it in
            // transaction metadata, audit payloads, or logs.
            $tx = $this->reservations->markCreated($tx, [
                'stripe_status' => $status,
            ], 'initiated', (string) $intent['id']);

            return ['transaction' => $tx, 'client_secret' => $intent['client_secret'] ?? null, 'payment_intent_id' => $intent['id']];
        } catch (\Throwable $e) {
            if ($response === null) {
                $this->reservations->markUnknown($tx, 'transport_or_timeout');
            } elseif ($response->serverError()) {
                $this->reservations->markUnknown($tx, 'provider_http_5xx');
            } elseif ($response->successful()) {
                $this->reservations->markUnknown($tx, 'post_provider_processing_error');
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Unable to create Stripe PaymentIntent; reservation remains held for reconciliation.', 0, $e);
        }
    }

    public function verifyAndSettleWebhook(string $rawBody, string $signatureHeader): PaymentTransaction
    {
        $webhookSecret = (string) $this->plugins->getSetting('gateway-stripe', 'webhook_secret', '');
        if ($webhookSecret === '') {
            throw new RuntimeException('Stripe webhook signing secret is not configured.');
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            if (str_contains($segment, '=')) {
                [$key, $value] = explode('=', trim($segment), 2);
                $parts[$key] = $value;
            }
        }
        $timestamp = $parts['t'] ?? '';
        if ($timestamp === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            throw new RuntimeException('Stripe webhook timestamp is missing or outside the 5-minute tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        $signatures = $parts['v1'] ?? [];
        if (is_string($signatures)) $signatures = [$signatures];
        $valid = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower((string) $signature))) $valid = true;
        }
        if (!$valid) throw new RuntimeException('Invalid Stripe-Signature HMAC.');

        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (($event['type'] ?? '') !== 'payment_intent.succeeded') {
            throw new RuntimeException('Stripe webhook event is not a successful PaymentIntent.');
        }
        $intent = $event['data']['object'] ?? [];
        $transactionRef = (string) ($intent['metadata']['transaction_ref'] ?? '');
        if ($transactionRef === '' || empty($intent['id'])) {
            throw new RuntimeException('Stripe PaymentIntent metadata is missing the LMS transaction reference.');
        }

        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $transactionRef)
            ->where('gateway_slug', 'gateway-stripe')
            ->firstOrFail();

        if ((string) $intent['id'] !== (string) $tx->external_reference) {
            throw new RuntimeException('Stripe PaymentIntent ID does not match the stored transaction.');
        }
        if ((int) ($intent['amount_received'] ?? $intent['amount'] ?? 0) !== (int) round((float) $tx->amount * 100)) {
            throw new RuntimeException('Stripe webhook amount does not match the invoice.');
        }
        if (strtoupper((string) ($intent['currency'] ?? '')) !== strtoupper((string) $tx->currency)) {
            throw new RuntimeException('Stripe webhook currency does not match the invoice.');
        }

        return $this->settlement->settleTransaction($tx, (string) $intent['id'], [
            'stripe_event_id' => (string) ($event['id'] ?? ''),
            'stripe_webhook_verified' => true,
        ]);
    }

    public function testCredentials(): array
    {
        $secret = (string) $this->plugins->getSetting('gateway-stripe', 'secret_key', '');
        if ($secret === '') throw new RuntimeException('Stripe Secret Key is not configured.');
        $response = Http::withBasicAuth($secret, '')->timeout(15)->get('https://api.stripe.com/v1/account');
        if (!$response->successful()) throw new RuntimeException((string) $response->json('error.message', 'Stripe authentication failed.'));
        return ['connected' => true, 'account_id' => $response->json('id'), 'country' => $response->json('country')];
    }
}
