<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\RefundProviderAdapter;
use Plugins\Modules\PaymentManagement\Services\RefundProviderResult;
use RuntimeException;

class StripeRefundAdapter implements RefundProviderAdapter
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function submit(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult
    {
        $secret = $this->secretKey();
        $paymentIntent = (string) $transaction->external_reference;
        if (!str_starts_with($paymentIntent, 'pi_')) {
            throw new RuntimeException('The settled Stripe PaymentIntent reference is missing.');
        }

        $amount = $this->toMinorUnits((float) $refund->amount, (string) $transaction->currency);
        $response = Http::asForm()
            ->withBasicAuth($secret, '')
            ->withHeaders(['Idempotency-Key' => (string) $refund->provider_idempotency_key])
            ->timeout(25)
            ->post('https://api.stripe.com/v1/refunds', [
                'payment_intent' => $paymentIntent,
                'amount' => $amount,
                'reason' => 'requested_by_customer',
                'metadata[transaction_ref]' => (string) $transaction->transaction_ref,
                'metadata[refund_id]' => (string) $refund->id,
            ]);

        if (!$response->successful()) {
            // A non-2xx response is not enough to prove that a timed-out or
            // retried request did not create a refund. Reconciliation uses the
            // same provider idempotency key and never releases the reservation.
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                null,
                'http_' . $response->status(),
                ['manual_reconciliation_required' => true]
            );
        }

        return $this->normalize($response->json(), $transaction, $refund);
    }

    public function reconcile(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult
    {
        $reference = (string) $refund->provider_refund_reference;
        if (!str_starts_with($reference, 're_')) {
            return new RefundProviderResult(RefundProviderResult::REQUIRES_REVIEW, null, 'missing_refund_reference');
        }

        $response = Http::withBasicAuth($this->secretKey(), '')
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.stripe.com/v1/refunds/' . rawurlencode($reference));

        if (!$response->successful()) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                $reference,
                'http_' . $response->status(),
                ['manual_reconciliation_required' => true]
            );
        }

        return $this->normalize($response->json(), $transaction, $refund);
    }

    private function normalize(mixed $payload, PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult
    {
        if (!is_array($payload)
            || !str_starts_with((string) ($payload['id'] ?? ''), 're_')
            || (string) ($payload['payment_intent'] ?? '') !== (string) $transaction->external_reference
            || (int) ($payload['amount'] ?? 0) !== $this->toMinorUnits((float) $refund->amount, (string) $transaction->currency)
            || strtolower((string) ($payload['currency'] ?? '')) !== strtolower((string) $transaction->currency)) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                is_array($payload) ? (string) ($payload['id'] ?? '') : null,
                'provider_response_mismatch',
                ['manual_reconciliation_required' => true]
            );
        }

        $providerStatus = (string) ($payload['status'] ?? '');
        $status = match ($providerStatus) {
            'succeeded' => RefundProviderResult::SUCCEEDED,
            'pending' => RefundProviderResult::PENDING,
            'failed', 'canceled' => RefundProviderResult::FAILED,
            default => RefundProviderResult::REQUIRES_REVIEW,
        };

        return new RefundProviderResult($status, (string) $payload['id'], $providerStatus, [
            'provider_amount_minor' => (int) $payload['amount'],
            'provider_currency' => strtoupper((string) $payload['currency']),
        ]);
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        $zeroDecimalCurrencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
            'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV',
            'XAF', 'XOF', 'XPF',
        ];
        $factor = in_array(strtoupper($currency), $zeroDecimalCurrencies, true) ? 1 : 100;

        return (int) round($amount * $factor);
    }

    private function secretKey(): string
    {
        $secret = (string) $this->plugins->getSetting('gateway-stripe', 'secret_key', '');
        if ($secret === '' || !preg_match('/^sk_(test|live)_/', $secret)) {
            throw new RuntimeException('A valid Stripe Secret Key is not configured.');
        }

        return $secret;
    }
}
