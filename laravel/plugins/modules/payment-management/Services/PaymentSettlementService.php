<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use App\Core\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use App\Models\User;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
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

            if ($newPaid >= (float) $invoice->net_amount && Schema::hasTable('commerce_orders')) {
                // Refuse to grant commerce access if the order, invoice, pending
                // enrollment, and source course disagree about branch ownership.
                $preflightOrders = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->get();
                foreach ($preflightOrders as $preflightOrder) {
                    if (Schema::hasColumn('commerce_orders', 'branch_id') && Schema::hasColumn('fee_invoices', 'branch_id')) {
                        $orderBranchId = $preflightOrder->branch_id !== null ? (int) $preflightOrder->branch_id : null;
                        $invoiceBranchId = $invoice->branch_id !== null ? (int) $invoice->branch_id : null;
                        if ($orderBranchId !== $invoiceBranchId) {
                            throw new RuntimeException('Commerce order and invoice branch ownership do not match.');
                        }

                        $buyer = User::query()->find((int) $preflightOrder->user_id);
                        if (!$buyer) {
                            throw new RuntimeException('Commerce order student account no longer exists.');
                        }

                        $assignedBranchId = null;
                        if (app(\\App\\Core\\Plugins\\PluginManager::class)->isEnabled('student-management')
                            && Schema::hasTable('students')
                            && Schema::hasColumn('students', 'branch_id')) {
                            $profileBranchId = DB::table('students')->where('user_id', $buyer->id)->value('branch_id');
                            if ($profileBranchId !== null) {
                                $assignedBranchId = (int) $profileBranchId;
                            }
                        }
                        if ($assignedBranchId === null && Schema::hasColumn('users', 'branch_id') && $buyer->branch_id !== null) {
                            $assignedBranchId = (int) $buyer->branch_id;
                        }
                        if ($assignedBranchId !== $orderBranchId) {
                            throw new RuntimeException('Student branch assignment changed after checkout; manual payment reconciliation is required.');
                        }
                    }
                }
                if (Schema::hasTable('enrollments') && Schema::hasColumn('enrollments', 'invoice_id')
                    && Schema::hasColumn('enrollments', 'branch_id') && Schema::hasTable('courses')
                    && Schema::hasColumn('courses', 'branch_id')) {
                    $pendingEnrollments = DB::table('enrollments')->where('invoice_id', $invoice->id)
                        ->where('status', 'pending')->lockForUpdate()->get();
                    foreach ($pendingEnrollments as $pendingEnrollment) {
                        $courseBranchId = DB::table('courses')->where('id', $pendingEnrollment->course_id)->value('branch_id');
                        if ($courseBranchId !== null && (int) ($pendingEnrollment->branch_id ?? 0) !== (int) $courseBranchId) {
                            throw new RuntimeException('Pending course enrollment branch does not match the source course.');
                        }
                    }
                }

                $orders = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->get();
                foreach ($orders as $order) {
                    if ($order->payment_status === 'paid') continue;
                    DB::table('commerce_orders')->where('id', $order->id)->update([
                        'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!Schema::hasTable('commerce_order_items') || !Schema::hasTable('commerce_entitlements')) continue;
                    $orderItems = DB::table('commerce_order_items')->where('order_id', $order->id)->get();
                    foreach ($orderItems as $orderItem) {
                        $itemDetails = json_decode((string) ($orderItem->metadata ?? '{}'), true) ?: [];
                        $entitlementDetails = $itemDetails['entitlement'] ?? $itemDetails;
                        $durationDays = isset($entitlementDetails['duration_days'])
                            ? max(1, min(3650, (int) $entitlementDetails['duration_days']))
                            : ($orderItem->item_type === 'subscription' ? 30 : null);
                        DB::table('commerce_entitlements')->insertOrIgnore([
                            'user_id' => $order->user_id,
                            'order_item_id' => $orderItem->id,
                            'entitlement_type' => $orderItem->item_type,
                            'resource_id' => $itemDetails['resource_id'] ?? $orderItem->item_id,
                            'details' => $orderItem->metadata,
                            'status' => 'active',
                            'starts_at' => now(),
                            'expires_at' => $durationDays ? now()->addDays($durationDays) : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        if ($orderItem->item_type === 'mock_test' && $orderItem->item_id
                            && Schema::hasTable('learn_earn_exam_access')) {
                            DB::table('learn_earn_exam_access')->updateOrInsert(
                                ['exam_id' => $orderItem->item_id, 'user_id' => $order->user_id],
                                [
                                    'status' => 'granted',
                                    'payment_reference' => $order->order_number,
                                    'granted_at' => now(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            );
                        }
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
                && app(\App\Core\Plugins\PluginManager::class)->isEnabled('referral-income')
                && Schema::hasTable('commerce_orders')) {
                $order = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->first();
                if ($order) {
                    $orderUpdates = ['status' => 'completed', 'payment_status' => 'paid', 'updated_at' => now()];
                    if (Schema::hasColumn('commerce_orders', 'paid_at')) $orderUpdates['paid_at'] = now();
                    DB::table('commerce_orders')->where('id', $order->id)->update($orderUpdates);
                    $buyer = User::query()->find((int) $order->user_id);
                    if ($buyer) {
                        app(ReferralIncomeService::class)->recordSettledPurchase(
                            $buyer,
                            'commerce_order',
                            (string) $order->id,
                            (float) $invoice->net_amount,
                            (string) $invoice->currency
                        );
                    }
                }
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
