<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use App\Core\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use RuntimeException;

class PaymentSettlementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function settleTransaction(
        PaymentTransaction $transaction,
        string $externalReference,
        array $sanitizedMetadata = [],
        ?int $actorId = null
    ): PaymentTransaction {
        // Strip any forbidden sensitive keys defensively
        foreach (['card_number', 'pan', 'cvv', 'cvc', 'security_code'] as $forbidden) {
            unset($sanitizedMetadata[$forbidden]);
        }

        return DB::transaction(function () use ($transaction, $externalReference, $sanitizedMetadata, $actorId): PaymentTransaction {
            $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
            if ($lockedTx->status === 'settled') {
                return $lockedTx;
            }

            // A verified late callback must never resurrect a fully refunded
            // transaction or credit its invoice a second time.
            if ($lockedTx->status === 'refunded') {
                throw new RuntimeException('A refunded transaction cannot be settled again.');
            }

            $invoice = FeeInvoice::query()->where('id', $lockedTx->invoice_id)->lockForUpdate()->firstOrFail();
            $newPaid = round((float) $invoice->paid_amount + (float) $lockedTx->amount, 2);
            if ($newPaid > (float) $invoice->net_amount + 0.01) {
                throw new RuntimeException('Settlement exceeds remaining invoice balance.');
            }

            $lockedTx->update([
                'status' => 'settled',
                'external_reference' => $externalReference,
                'metadata' => array_merge($lockedTx->metadata ?? [], $sanitizedMetadata),
                'settled_at' => now(),
            ]);

            $invoice->update([
                'paid_amount' => $newPaid,
                'status' => $newPaid >= (float) $invoice->net_amount ? 'paid' : 'partial',
            ]);

            if ($newPaid >= (float) $invoice->net_amount
                && Schema::hasTable('enrollments')
                && Schema::hasColumn('enrollments', 'invoice_id')) {
                DB::table('enrollments')
                    ->where('invoice_id', $invoice->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'active', 'updated_at' => now()]);
            }

            $this->audit->log('payment.settled', 'payment_transaction', (string) $lockedTx->id, [
                'gateway' => $lockedTx->gateway_slug,
                'amount' => $lockedTx->amount,
                'invoice_id' => $invoice->id,
            ], $actorId);

            return $lockedTx->fresh();
        });
    }
}
