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

            if ($newPaid >= (float) $invoice->net_amount && Schema::hasTable('commerce_orders')) {
                $orders = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->get();
                foreach ($orders as $order) {
                    if ($order->payment_status === 'paid') continue;
                    DB::table('commerce_orders')->where('id', $order->id)->update([
                        'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!Schema::hasTable('commerce_order_items') || !Schema::hasTable('commerce_entitlements')) continue;
                    $orderItems = DB::table('commerce_order_items')->where('order_id', $order->id)->get();
                    foreach ($orderItems as $orderItem) {
                        DB::table('commerce_entitlements')->insertOrIgnore([
                            'user_id' => $order->user_id,
                            'order_item_id' => $orderItem->id,
                            'entitlement_type' => $orderItem->item_type,
                            'resource_id' => $orderItem->item_id,
                            'details' => $orderItem->metadata,
                            'status' => 'active',
                            'starts_at' => now(),
                            'expires_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        if ($orderItem->item_type === 'ebook' && $orderItem->item_id
                            && Schema::hasTable('storefront_item_access')) {
                            DB::table('storefront_item_access')->insertOrIgnore([
                                'storefront_item_id' => $orderItem->item_id,
                                'user_id' => $order->user_id,
                                'payment_transaction_id' => $lockedTx->id,
                                'granted_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

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
