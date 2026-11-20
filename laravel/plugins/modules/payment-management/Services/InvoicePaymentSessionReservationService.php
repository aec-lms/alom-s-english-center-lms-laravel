<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use RuntimeException;

class InvoicePaymentSessionReservationService
{
    private const ACTIVE_STATUSES = [
        'initiated',
        'pending',
        'risk_review',
        'processing',
        'authorized',
        'requires_action',
        'requires_confirmation',
        'requires_capture',
    ];

    /**
     * Reserve the outstanding invoice balance for exactly one provider session.
     *
     * The invoice row is the common serialization key across gateways. The
     * transaction row is inserted while that lock is held, so a competing
     * gateway request must observe the reservation after it acquires the lock.
     *
     * @return array{invoice: FeeInvoice, transaction: PaymentTransaction, remaining: float, reused: bool}
     */
    public function reserve(
        FeeInvoice $invoice,
        string $gatewaySlug,
        string $transactionPrefix,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): array {
        return DB::transaction(function () use ($invoice, $gatewaySlug, $transactionPrefix, $metadata, $idempotencyKey): array {
            $lockedInvoice = FeeInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $remaining = round((float) $lockedInvoice->net_amount - (float) $lockedInvoice->paid_amount, 2);
            if ($remaining <= 0 || in_array($lockedInvoice->status, ['paid', 'cancelled', 'void'], true)) {
                throw new RuntimeException('Invoice has no payable balance.');
            }

            if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
                $existingForKey = PaymentTransaction::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->where('gateway_slug', $gatewaySlug)
                    ->where('metadata->idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingForKey) {
                    if (!in_array($existingForKey->status, self::ACTIVE_STATUSES, true)) {
                        throw new RuntimeException('This idempotency key is already bound to a terminal payment transaction. Use a new key for a new attempt.');
                    }

                    return [
                        'invoice' => $lockedInvoice,
                        'transaction' => $existingForKey,
                        'remaining' => round((float) $existingForKey->amount, 2),
                        'reused' => true,
                    ];
                }
            }

            $active = PaymentTransaction::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw new RuntimeException(
                    'This invoice already has an active or ambiguous payment session (' . $active->gateway_slug . '). Reconcile or explicitly release that session before choosing another gateway.'
                );
            }

            $transaction = PaymentTransaction::query()->create([
                'transaction_ref' => $transactionPrefix . '-' . strtoupper(bin2hex(random_bytes(8))),
                'invoice_id' => $lockedInvoice->id,
                'branch_id' => $lockedInvoice->branch_id,
                'gateway_slug' => $gatewaySlug,
                'amount' => $remaining,
                'currency' => strtoupper((string) $lockedInvoice->currency),
                'status' => 'initiated',
                'metadata' => array_merge($metadata, [
                    'idempotency_key' => $idempotencyKey,
                    'session_outcome' => 'reserved',
                ]),
            ]);

            return [
                'invoice' => $lockedInvoice,
                'transaction' => $transaction,
                'remaining' => $remaining,
                'reused' => false,
            ];
        }, 3);
    }

    public function markCreated(PaymentTransaction $transaction, array $providerMetadata = []): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $providerMetadata): PaymentTransaction {
            $locked = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'metadata' => array_merge($locked->metadata ?? [], $providerMetadata, [
                    'session_outcome' => 'created',
                    'session_created_at' => now()->toIso8601String(),
                ]),
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Keep an unknown provider outcome reserved. A timeout or malformed
     * successful response does not prove that the provider failed to create
     * a chargeable session.
     */
    public function markUnknown(PaymentTransaction $transaction, string $reasonCode): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $reasonCode): PaymentTransaction {
            $locked = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'metadata' => array_merge($locked->metadata ?? [], [
                    'session_outcome' => 'unknown',
                    'session_unknown_reason' => preg_replace('/[^a-z0-9_.-]/i', '_', $reasonCode),
                    'session_unknown_at' => now()->toIso8601String(),
                    'manual_reconciliation_required' => true,
                ]),
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Release a reservation only after a definite provider rejection.
     */
    public function markRejected(PaymentTransaction $transaction, string $reasonCode): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $reasonCode): PaymentTransaction {
            $locked = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => 'failed',
                'metadata' => array_merge($locked->metadata ?? [], [
                    'session_outcome' => 'rejected',
                    'session_rejection_code' => preg_replace('/[^a-z0-9_.-]/i', '_', $reasonCode),
                    'session_rejected_at' => now()->toIso8601String(),
                ]),
            ]);

            return $locked->fresh();
        }, 3);
    }
}
