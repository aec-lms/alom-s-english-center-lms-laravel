<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;

class StripeGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function initiateTokenizedCheckout(FeeInvoice $invoice, string $paymentToken, ?string $idempotencyKey = null, ?float $requestedAmount = null): array
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

        if ($idempotencyKey) {
            $existing = PaymentTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('gateway_slug', 'gateway-stripe')
                ->where('metadata->idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return [
                    'transaction' => $existing,
                    'client_secret' => $existing->metadata['client_secret'] ?? null,
                    'payment_intent_id' => $existing->external_reference,
                ];
            }
        }

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        $chargeAmount = round($requestedAmount ?? $remaining, 2);
        if ($remaining <= 0 || $chargeAmount < 1 || $chargeAmount > $remaining) {
            throw new RuntimeException('Invoice is already fully settled.');
        }

        $tx = PaymentTransaction::query()->create([
            'transaction_ref' => 'STRP-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'gateway-stripe',
            'amount' => $chargeAmount,
            'currency' => strtoupper($invoice->currency),
            'status' => 'initiated',
            'metadata' => ['idempotency_key' => $idempotencyKey, 'payment_method_id' => $paymentToken],
        ]);

        try {
            $amountMinor = (int) round($chargeAmount * 100);
            $response = Http::asForm()
                ->withBasicAuth($secretKey, '')
                ->withHeaders($idempotencyKey ? ['Idempotency-Key' => $idempotencyKey] : [])
                ->timeout(25)
                ->post('https://api.stripe.com/v1/payment_intents', [
                    'amount' => $amountMinor,
                    'currency' => strtolower($invoice->currency),
                    'payment_method' => $paymentToken,
                    'confirm' => 'true',
                    'automatic_payment_methods[enabled]' => 'true',
                    'metadata[transaction_ref]' => $tx->transaction_ref,
                    'metadata[invoice_number]' => (string) $invoice->invoice_number,
                    'description' => 'AEC LMS invoice ' . $invoice->invoice_number,
                    'return_url' => url('/payments/stripe/return'),
                ]);

            if (!$response->successful()) {
                $message = (string) $response->json('error.message', 'Stripe PaymentIntent creation failed.');
                throw new RuntimeException($message);
            }

            $intent = $response->json();
            if (!is_array($intent) || empty($intent['id']) || !str_starts_with((string) $intent['id'], 'pi_')) {
                throw new RuntimeException('Stripe returned an invalid PaymentIntent response.');
            }

            $status = (string) ($intent['status'] ?? 'requires_payment_method');
            $tx->update([
                'external_reference' => $intent['id'],
                'status' => $status === 'succeeded' ? 'pending' : 'initiated',
                'metadata' => array_merge($tx->metadata ?? [], [
                    'stripe_status' => $status,
                    'client_secret' => $intent['client_secret'] ?? null,
                    'payment_method_id' => null,
                ]),
            ]);

            if ($status === 'succeeded') {
                return ['transaction' => $tx->fresh(), 'client_secret' => $intent['client_secret'] ?? null, 'payment_intent_id' => $intent['id']];
            }

            if (!in_array($status, ['requires_action', 'requires_confirmation', 'processing', 'requires_capture'], true)) {
                throw new RuntimeException('Stripe payment could not be confirmed. Status: ' . $status);
            }

            return ['transaction' => $tx->fresh(), 'client_secret' => $intent['client_secret'] ?? null, 'payment_intent_id' => $intent['id']];
        } catch (\Throwable $e) {
            $tx->update(['status' => 'failed']);
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Unable to create Stripe PaymentIntent.', 0, $e);
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
