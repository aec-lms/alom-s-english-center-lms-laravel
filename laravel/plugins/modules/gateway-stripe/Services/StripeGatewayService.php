<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Services;

use App\Core\Plugins\PluginManager;
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

    public function initiateTokenizedCheckout(FeeInvoice $invoice, string $paymentToken, ?string $idempotencyKey = null): PaymentTransaction
    {
        $secretKey = (string) $this->plugins->getSetting('gateway-stripe', 'secret_key', '');
        if ($secretKey === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = PaymentTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('gateway_slug', 'gateway-stripe')
                ->where('metadata->idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0) {
            throw new RuntimeException('Invoice is already fully settled.');
        }

        return PaymentTransaction::query()->create([
            'transaction_ref' => 'STRP-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'gateway-stripe',
            'amount' => $remaining,
            'currency' => $invoice->currency,
            'status' => 'initiated',
            'metadata' => [
                'token_hash' => hash('sha256', $paymentToken),
                'idempotency_key' => $idempotencyKey,
                'sandbox' => $this->plugins->getSetting('gateway-stripe', 'sandbox_mode', '1') === '1',
            ],
        ]);
    }

    public function verifyAndSettleWebhook(string $rawBody, string $signatureHeader, array $payload): PaymentTransaction
    {
        $webhookSecret = (string) $this->plugins->getSetting('gateway-stripe', 'webhook_secret', '');
        if ($webhookSecret === '') {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            if (str_contains($segment, '=')) {
                [$k, $v] = explode('=', trim($segment), 2);
                $parts[$k] = $v;
            }
        }

        $timestamp = $parts['t'] ?? '';
        $v1Sig = strtolower($parts['v1'] ?? '');
        if ($timestamp === '' || $v1Sig === '' || !ctype_digit($timestamp)) {
            throw new RuntimeException('Malformed Stripe-Signature header.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new RuntimeException('Stripe webhook timestamp outside allowed 5-minute tolerance.');
        }

        $expectedSig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        if (!hash_equals($expectedSig, $v1Sig)) {
            throw new RuntimeException('Invalid Stripe webhook HMAC signature.');
        }

        $tranRef = (string) ($payload['transaction_ref'] ?? ($payload['data']['object']['metadata']['transaction_ref'] ?? ''));
        $intentId = (string) ($payload['payment_intent_id'] ?? ($payload['data']['object']['id'] ?? 'pi_' . bin2hex(random_bytes(4))));

        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $tranRef)
            ->where('gateway_slug', 'gateway-stripe')
            ->firstOrFail();

        return $this->settlement->settleTransaction($tx, $intentId, ['stripe_webhook_verified' => true]);
    }
}
