<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use App\Core\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use RuntimeException;
use Throwable;

class RefundOrchestrationService
{
    public function __construct(
        private readonly RefundProviderRegistry $providers,
        private readonly RefundSettlementService $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function requestOnlineRefund(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        int $actorId,
        string $idempotencyKey,
    ): PaymentRefund {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 120 || trim($reason) === '') {
            throw new RuntimeException('A valid idempotency key and non-empty refund reason are required.');
        }
        $invoiceId = PaymentTransaction::query()->whereKey($transaction->id)->value('invoice_id');

        $refund = DB::transaction(function () use ($transaction, $invoiceId, $amount, $reason, $actorId, $idempotencyKey): PaymentRefund {
            if ($invoiceId === null) {
                throw new RuntimeException('Online refunds require a linked invoice.');
            }

            $lockedInvoice = FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $lockedTx = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($lockedTx->status !== 'settled' || $lockedTx->gateway_slug === 'offline') {
                throw new RuntimeException('Only settled online payment transactions can request a provider refund.');
            }
            abort_unless(
                strtoupper((string) $lockedTx->currency) === strtoupper((string) $lockedInvoice->currency),
                409,
                'Payment transaction and invoice currencies do not match.'
            );
            $this->settlement->assertActorBranchScope($actorId, $lockedTx);

            // Resolve/validate the adapter and merchant capability before writing
            // a request. All adapters are disabled by default in plugin settings.
            $this->providers->forTransaction($lockedTx);
            $this->settlement->assertNoGiftOrderForInvoice((int) $invoiceId, $lockedInvoice, $lockedTx);

            $normalizedAmount = round($amount, 2);
            if ($normalizedAmount <= 0) {
                throw new RuntimeException('Refund amount must be greater than zero.');
            }

            $fingerprint = $this->fingerprint($lockedTx, $normalizedAmount, $reason, $actorId);
            $existing = PaymentRefund::query()
                ->where('payment_transaction_id', $lockedTx->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                abort_unless(
                    $existing->request_fingerprint !== null
                        && hash_equals((string) $existing->request_fingerprint, $fingerprint),
                    409,
                    'This refund idempotency key was already used with different parameters.'
                );

                return $existing;
            }

            $this->settlement->assertInvoiceRefundAmountAvailable($lockedInvoice, $normalizedAmount);

            $reserved = (float) PaymentRefund::query()
                ->where('payment_transaction_id', $lockedTx->id)
                ->whereIn('status', ['requested', 'submitted', 'pending', 'requires_review', 'succeeded'])
                ->sum('amount');
            abort_unless(
                round($reserved + $normalizedAmount, 2) <= (float) $lockedTx->amount,
                422,
                'Refund exceeds the remaining refundable transaction amount.'
            );

            $created = PaymentRefund::query()->create([
                'payment_transaction_id' => $lockedTx->id,
                'amount' => $normalizedAmount,
                'reason' => trim($reason),
                'gateway_slug' => $lockedTx->gateway_slug,
                'status' => 'requested',
                'requested_by' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'metadata' => ['approval_required' => true],
            ]);
            $this->audit->logRequired('payment.refund_requested', 'payment_transaction', (string) $lockedTx->id, [
                'refund_id' => $created->id,
                'amount' => $created->amount,
                'gateway' => $created->gateway_slug,
                'status' => $created->status,
            ], $actorId);

            return $created;
        }, 3);

        return $refund;
    }

    public function approveAndSubmit(PaymentRefund $refund, int $actorId): PaymentRefund
    {
        $refundId = (int) $refund->id;
        $transactionId = (int) $refund->payment_transaction_id;
        $invoiceId = PaymentTransaction::query()->whereKey($transactionId)->value('invoice_id');

        $prepared = DB::transaction(function () use ($refundId, $transactionId, $invoiceId, $actorId): array {
            if ($invoiceId === null) {
                throw new RuntimeException('Online refunds require a linked invoice.');
            }

            $lockedInvoice = FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $transaction = PaymentTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $this->settlement->assertActorBranchScope($actorId, $transaction);
            abort_unless(
                strtoupper((string) $transaction->currency) === strtoupper((string) $lockedInvoice->currency),
                409,
                'Payment transaction and invoice currencies do not match.'
            );
            $this->settlement->assertNoGiftOrderForInvoice((int) $invoiceId, $lockedInvoice, $transaction);
            $lockedRefund = PaymentRefund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
            $this->settlement->assertInvoiceRefundAmountAvailable(
                $lockedInvoice,
                (float) $lockedRefund->amount,
                (int) $lockedRefund->id,
            );
            $this->settlement->assertRefundAmountAvailable(
                $transaction,
                (float) $lockedRefund->amount,
                (int) $lockedRefund->id,
            );

            abort_unless($lockedRefund->status === 'requested', 409, 'Only a requested refund can be approved.');
            abort_unless((int) $lockedRefund->requested_by !== $actorId, 403, 'A refund requester cannot approve their own refund.');
            abort_unless($transaction->status === 'settled', 409, 'The source payment is no longer in a settled refundable state.');

            $adapter = $this->providers->forTransaction($transaction);
            $providerKey = 'AEC-RF-' . strtoupper(base_convert((string) $lockedRefund->id, 10, 36));
            $lockedRefund->update([
                'approved_by' => $actorId,
                'provider_idempotency_key' => $providerKey,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
            $this->audit->logRequired('payment.refund_approved', 'payment_transaction', (string) $transactionId, [
                'refund_id' => $lockedRefund->id,
                'amount' => $lockedRefund->amount,
                'gateway' => $lockedRefund->gateway_slug,
                'requester_id' => $lockedRefund->requested_by,
                'approver_id' => $actorId,
            ], $actorId);

            return [$transaction->fresh(), $lockedRefund->fresh(), $adapter];
        }, 3);

        /** @var PaymentTransaction $transaction */
        [$transaction, $preparedRefund, $adapter] = $prepared;

        try {
            $result = $adapter->submit($transaction, $preparedRefund);
        } catch (Throwable) {
            // A timeout or unclassified transport error may have reached the
            // provider. Keep the amount reserved and require a provider query.
            return $this->updateProviderState(
                $preparedRefund,
                RefundProviderResult::REQUIRES_REVIEW,
                null,
                'submission_outcome_unknown',
                ['manual_reconciliation_required' => true],
                false,
                $actorId,
            );
        }

        return $this->applyProviderResult($preparedRefund, $result, $actorId);
    }

    public function rejectRequest(PaymentRefund $refund, int $actorId): PaymentRefund
    {
        $refundId = (int) $refund->id;
        $transactionId = (int) $refund->payment_transaction_id;
        $invoiceId = PaymentTransaction::query()->whereKey($transactionId)->value('invoice_id');

        if ($invoiceId === null) {
            throw new RuntimeException('Online refunds require a linked invoice.');
        }

        return DB::transaction(function () use ($refundId, $transactionId, $invoiceId, $actorId): PaymentRefund {
            FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $transaction = PaymentTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $this->settlement->assertActorBranchScope($actorId, $transaction);
            $lockedRefund = PaymentRefund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();

            abort_unless($lockedRefund->status === 'requested', 409, 'Only a requested refund can be rejected.');
            abort_unless((int) $lockedRefund->requested_by !== $actorId, 403, 'A refund requester cannot reject their own request.');

            $lockedRefund->update([
                'status' => 'rejected',
                'rejected_by' => $actorId,
                'rejected_at' => now(),
            ]);
            $this->audit->logRequired('payment.refund_rejected', 'payment_transaction', (string) $transactionId, [
                'refund_id' => $lockedRefund->id,
                'amount' => $lockedRefund->amount,
                'gateway' => $lockedRefund->gateway_slug,
                'requester_id' => $lockedRefund->requested_by,
                'rejecter_id' => $actorId,
            ], $actorId);

            return $lockedRefund->fresh();
        }, 3);
    }

    public function reconcile(PaymentRefund $refund, ?int $actorId = null): PaymentRefund
    {
        $refundId = (int) $refund->id;
        $transactionId = (int) $refund->payment_transaction_id;
        $invoiceId = PaymentTransaction::query()->whereKey($transactionId)->value('invoice_id');

        $prepared = DB::transaction(function () use ($refundId, $transactionId, $invoiceId, $actorId): array {
            if ($invoiceId !== null && Schema::hasTable('fee_invoices')) {
                // If an invoice was removed after provider submission, still
                // query the provider and retain the confirmed result for manual
                // ledger review instead of leaving an invisible pending refund.
                FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            }
            $transaction = PaymentTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $this->settlement->assertActorBranchScope($actorId, $transaction);
            $lockedRefund = PaymentRefund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();

            abort_unless(
                in_array($lockedRefund->status, ['submitted', 'pending', 'requires_review'], true),
                409,
                'Only submitted, pending or ambiguous refunds can be reconciled.'
            );

            return [$transaction->fresh(), $lockedRefund->fresh(), $this->providers->forTransaction($transaction, true)];
        }, 3);

        /** @var PaymentTransaction $transaction */
        [$transaction, $preparedRefund, $adapter] = $prepared;
        try {
            $result = $adapter->reconcile($transaction, $preparedRefund);
        } catch (Throwable) {
            return $this->updateProviderState(
                $preparedRefund,
                RefundProviderResult::REQUIRES_REVIEW,
                $preparedRefund->provider_refund_reference,
                'reconciliation_unavailable',
                ['manual_reconciliation_required' => true],
                true,
                $actorId,
            );
        }

        $updated = $this->applyProviderResult($preparedRefund, $result, $actorId);
        return $this->updateProviderState(
            $updated,
            $updated->status,
            $updated->provider_refund_reference,
            $updated->provider_status,
            ['last_reconciled_at' => now()->toIso8601String()],
            true,
            $actorId,
        );
    }

    private function applyProviderResult(PaymentRefund $refund, RefundProviderResult $result, ?int $actorId = null): PaymentRefund
    {
        if ($result->status === RefundProviderResult::SUCCEEDED) {
            // Persist the provider's final status without marking the ledger
            // complete. The ledger transition and succeeded state are committed
            // together; a crash or gift/entitlement guard leaves this reserved.
            $pending = $this->updateProviderState(
                $refund,
                RefundProviderResult::PENDING,
                $result->providerReference,
                $result->providerStatus,
                $result->metadata,
                false,
                $actorId,
            );

            try {
                return $this->settlement->applyProviderConfirmedRefund($pending);
            } catch (Throwable $exception) {
                // Keep provider secrets and exception messages out of persisted
                // metadata; the exception class/code are safe diagnostic signals
                // for an administrator investigating a held financial ledger.
                return $this->updateProviderState(
                    $pending,
                    RefundProviderResult::REQUIRES_REVIEW,
                    $result->providerReference,
                    $result->providerStatus,
                    [
                        'provider_confirmed_ledger_review_required' => true,
                        'ledger_review_exception_type' => get_class($exception),
                        'ledger_review_exception_code' => (int) $exception->getCode(),
                    ],
                    false,
                    $actorId,
                );
            }
        }

        return $this->updateProviderState(
            $refund,
            $result->status,
            $result->providerReference,
            $result->providerStatus,
            $result->metadata,
            false,
            $actorId,
        );
    }

    private function updateProviderState(
        PaymentRefund $refund,
        string $status,
        ?string $providerReference,
        ?string $providerStatus,
        array $metadata = [],
        bool $reconciled = false,
        ?int $actorId = null,
    ): PaymentRefund {
        $transactionId = (int) $refund->payment_transaction_id;
        $invoiceId = PaymentTransaction::query()->whereKey($transactionId)->value('invoice_id');

        $updated = DB::transaction(function () use ($refund, $transactionId, $invoiceId, $status, $providerReference, $providerStatus, $metadata, $reconciled, $actorId): PaymentRefund {
            if ($invoiceId !== null && Schema::hasTable('fee_invoices')) {
                FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            }
            PaymentTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $locked = PaymentRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->ledger_applied_at !== null) {
                if ($reconciled) {
                    $locked->update(['reconciled_at' => now()]);
                }
                $fresh = $locked->fresh();
                $this->audit->logRequired('payment.refund_provider_state_changed', 'payment_transaction', (string) $transactionId, [
                    'refund_id' => $fresh->id,
                    'status' => $fresh->status,
                    'provider_status' => $fresh->provider_status,
                    'provider_reference_present' => (string) $fresh->provider_refund_reference !== '',
                ], $actorId);
                return $fresh;
            }

            $changes = [
                'status' => $status,
                'provider_status' => $providerStatus,
                'metadata' => array_merge($locked->metadata ?? [], $metadata),
            ];
            if ($providerReference !== null && $providerReference !== '') {
                $changes['provider_refund_reference'] = $providerReference;
            }
            if ($status === RefundProviderResult::SUCCEEDED) {
                $changes['succeeded_at'] = $locked->succeeded_at ?? now();
            } elseif ($status === RefundProviderResult::FAILED) {
                $changes['failed_at'] = now();
            }
            if ($reconciled) {
                $changes['reconciled_at'] = now();
            }

            $locked->update($changes);
            $fresh = $locked->fresh();
            $this->audit->logRequired('payment.refund_provider_state_changed', 'payment_transaction', (string) $transactionId, [
                'refund_id' => $fresh->id,
                'status' => $fresh->status,
                'provider_status' => $fresh->provider_status,
                'provider_reference_present' => (string) $fresh->provider_refund_reference !== '',
            ], $actorId);
            return $fresh;
        }, 3);

        return $updated;
    }

    private function fingerprint(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        int $actorId,
    ): string {
        return hash('sha256', json_encode([
            'transaction_id' => (int) $transaction->id,
            'gateway' => (string) $transaction->gateway_slug,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper((string) $transaction->currency),
            'reason' => trim($reason),
            'requester_id' => $actorId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
