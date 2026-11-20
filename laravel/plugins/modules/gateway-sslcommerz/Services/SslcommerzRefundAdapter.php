<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\RefundProviderAdapter;
use Plugins\Modules\PaymentManagement\Services\RefundProviderResult;
use RuntimeException;

class SslcommerzRefundAdapter implements RefundProviderAdapter
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function submit(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult
    {
        $this->assertSupportedCurrency($transaction);
        [$storeId, $storePassword, $endpoint] = $this->credentials();
        $bankTransactionId = trim((string) ($transaction->metadata['bank_transaction_id'] ?? ''));
        if ($bankTransactionId === '') {
            throw new RuntimeException('The verified SSLCOMMERZ bank transaction ID is missing.');
        }

        $providerRefundId = (string) $refund->provider_idempotency_key;
        if ($providerRefundId === '' || strlen($providerRefundId) > 30) {
            throw new RuntimeException('The SSLCOMMERZ refund transaction ID is invalid.');
        }

        $response = Http::acceptJson()->timeout(25)->get($endpoint, [
            'bank_tran_id' => $bankTransactionId,
            'refund_trans_id' => $providerRefundId,
            'refund_amount' => number_format((float) $refund->amount, 2, '.', ''),
            'refund_remarks' => mb_substr((string) $refund->reason, 0, 255),
            'refe_id' => substr((string) $transaction->transaction_ref, 0, 50),
            'store_id' => $storeId,
            'store_passwd' => $storePassword,
            'v' => '1',
            'format' => 'json',
        ]);

        if (!$response->successful()) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                null,
                'http_' . $response->status(),
                ['manual_reconciliation_required' => true]
            );
        }

        $payload = $response->json();
        if (!is_array($payload)
            || strtoupper((string) ($payload['APIConnect'] ?? '')) !== 'DONE'
            || !hash_equals($bankTransactionId, (string) ($payload['bank_tran_id'] ?? ''))
            || !hash_equals((string) $transaction->transaction_ref, (string) ($payload['trans_id'] ?? ''))) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                null,
                'provider_response_mismatch',
                ['manual_reconciliation_required' => true]
            );
        }

        $reference = trim((string) ($payload['refund_ref_id'] ?? ''));
        $providerStatus = strtolower((string) ($payload['status'] ?? ''));
        if ($providerStatus === 'failed') {
            return new RefundProviderResult(RefundProviderResult::FAILED, $reference ?: null, $providerStatus);
        }
        if (in_array($providerStatus, ['success', 'processing'], true) && $reference !== '') {
            // SSLCOMMERZ "success" means refund initiation, not that the funds
            // have reached the buyer. Always query the refund reference later.
            return new RefundProviderResult(RefundProviderResult::PENDING, $reference, $providerStatus, [
                'refund_trans_id' => $providerRefundId,
                'bank_transaction_id' => $bankTransactionId,
            ]);
        }

        return new RefundProviderResult(
            RefundProviderResult::REQUIRES_REVIEW,
            $reference ?: null,
            $providerStatus ?: 'unknown',
            ['manual_reconciliation_required' => true]
        );
    }

    public function reconcile(PaymentTransaction $transaction, PaymentRefund $refund): RefundProviderResult
    {
        $this->assertSupportedCurrency($transaction);
        [$storeId, $storePassword, $endpoint] = $this->credentials();
        $reference = trim((string) $refund->provider_refund_reference);
        $bankTransactionId = trim((string) ($transaction->metadata['bank_transaction_id'] ?? ''));
        if ($reference === '' || $bankTransactionId === '') {
            return new RefundProviderResult(RefundProviderResult::REQUIRES_REVIEW, $reference ?: null, 'missing_reconciliation_reference');
        }

        $response = Http::acceptJson()->timeout(20)->get($endpoint, [
            'refund_ref_id' => $reference,
            'store_id' => $storeId,
            'store_passwd' => $storePassword,
            'format' => 'json',
        ]);
        if (!$response->successful()) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                $reference,
                'http_' . $response->status(),
                ['manual_reconciliation_required' => true]
            );
        }

        $payload = $response->json();
        if (!is_array($payload)
            || strtoupper((string) ($payload['APIConnect'] ?? '')) !== 'DONE'
            || !hash_equals($bankTransactionId, (string) ($payload['bank_tran_id'] ?? ''))
            || !hash_equals((string) $transaction->transaction_ref, (string) ($payload['tran_id'] ?? ''))
            || !hash_equals($reference, (string) ($payload['refund_ref_id'] ?? ''))) {
            return new RefundProviderResult(
                RefundProviderResult::REQUIRES_REVIEW,
                $reference,
                'provider_response_mismatch',
                ['manual_reconciliation_required' => true]
            );
        }

        $providerStatus = strtolower((string) ($payload['status'] ?? ''));
        $status = match ($providerStatus) {
            'refunded' => RefundProviderResult::SUCCEEDED,
            'processing' => RefundProviderResult::PENDING,
            // The provider documentation's "cancelled" description is
            // ambiguous; never treat it as a confirmed refund.
            'cancelled' => RefundProviderResult::REQUIRES_REVIEW,
            default => RefundProviderResult::REQUIRES_REVIEW,
        };

        return new RefundProviderResult($status, $reference, $providerStatus, [
            'bank_transaction_id' => $bankTransactionId,
        ]);
    }

    private function assertSupportedCurrency(PaymentTransaction $transaction): void
    {
        if (strtoupper((string) $transaction->currency) !== 'BDT') {
            throw new RuntimeException('SSLCOMMERZ refunds are enabled only for BDT transactions until the provider confirms currency-specific refund behavior.');
        }
    }

    private function credentials(): array
    {
        $storeId = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePassword = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePassword === '') {
            throw new RuntimeException('SSLCOMMERZ Store ID and Store Password are not configured.');
        }

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $endpoint = $sandbox
            ? 'https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php'
            : 'https://securepay.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php';

        return [$storeId, $storePassword, $endpoint];
    }
}
