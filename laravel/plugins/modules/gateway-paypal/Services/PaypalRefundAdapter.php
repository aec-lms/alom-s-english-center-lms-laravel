<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\RefundProviderAdapter;
use Plugins\Modules\PaymentManagement\Services\RefundProviderResult;
use RuntimeException;

class PaypalRefundAdapter implements RefundProviderAdapter
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function submit(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult
    {
        $captureId = trim((string) $transaction->external_reference);
        if ($captureId === '' || $captureId === (string) ($transaction->metadata['paypal_order_id'] ?? '')) {
            throw new RuntimeException('The verified PayPal capture ID is missing from the settled transaction.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) $refund->provider_idempotency_key,
                'Prefer' => 'return=representation',
            ])
            ->timeout(25)
            ->post($this->apiBase() . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', [
                'amount' => [
                    'value' => number_format((float) $refund->amount, 2, '.', ''),
                    'currency_code' => strtoupper((string) $transaction->currency),
                ],
                'invoice_id' => (string) $transaction->transaction_ref,
                'custom_id' => 'refund-' . $refund->id,
                'note_to_payer' => mb_substr((string) $refund->reason, 0, 255),
            ]);

        if (!$response->successful()) {
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
        $reference = trim((string) $refund->provider_refund_reference);
        if ($reference === '') {
            return new RefundProviderResult(RefundProviderResult::REQUIRES_REVIEW, null, 'missing_refund_reference');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(20)
            ->get($this->apiBase() . '/v2/payments/refunds/' . rawurlencode($reference));

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
        $amount = is_array($payload) ? ($payload['amount'] ?? []) : [];
        $upLink = is_array($payload) ? (collect($payload['links'] ?? [])->firstWhere('rel', 'up')['href'] ?? '') : '';
        $upHost = strtolower((string) parse_url((string) $upLink, PHP_URL_HOST));
        $upPath = (string) parse_url((string) $upLink, PHP_URL_PATH);
        $relatedCaptureId = basename($upPath);
        $expectedHost = $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1'
            ? 'api-m.sandbox.paypal.com'
            : 'api-m.paypal.com';
        if (!is_array($payload)
            || trim((string) ($payload['id'] ?? '')) === ''
            || $upHost !== $expectedHost
            || $relatedCaptureId !== (string) $transaction->external_reference
            || round((float) ($amount['value'] ?? 0), 2) !== round((float) $refund->amount, 2)
            || strtoupper((string) ($amount['currency_code'] ?? '')) !== strtoupper((string) $transaction->currency)) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                is_array($payload) ? (string) ($payload['id'] ?? '') : null,
                'provider_response_mismatch',
                ['manual_reconciliation_required' => true]
            );
        }

        $providerStatus = strtoupper((string) ($payload['status'] ?? ''));
        $status = match ($providerStatus) {
            'COMPLETED' => RefundProviderResult::SUCCEEDED,
            'PENDING' => RefundProviderResult::PENDING,
            'FAILED', 'CANCELLED' => RefundProviderResult::FAILED,
            default => RefundProviderResult::REQUIRES_REVIEW,
        };

        return new RefundProviderResult($status, (string) $payload['id'], $providerStatus, [
            'provider_currency' => strtoupper((string) $amount['currency_code']),
            'provider_amount' => number_format((float) $amount['value'], 2, '.', ''),
        ]);
    }

    private function accessToken(): string
    {
        $clientId = (string) $this->plugins->getSetting('gateway-paypal', 'client_id', '');
        $secret = (string) $this->plugins->getSetting('gateway-paypal', 'client_secret', '');
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('PayPal Client ID and Secret are not configured.');
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->timeout(20)
            ->post($this->apiBase() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if (!$response->successful() || !is_string($response->json('access_token'))) {
            throw new RuntimeException('PayPal refund authentication failed.');
        }

        return (string) $response->json('access_token');
    }

    private function apiBase(): string
    {
        return $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
}
