<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use App\Core\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\CourseManagement\Jobs\SendGiftRecipientEmailJob;
use App\Core\Plugins\PluginManager;
use App\Models\User;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
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
            if ($lockedTx->status !== 'initiated') {
                throw new RuntimeException('Only initiated payment transactions can be settled.');
            }

            $invoice = FeeInvoice::query()->where('id', $lockedTx->invoice_id)->lockForUpdate()->firstOrFail();

            // Referral attribution and first-paid-order settlement serialize on
            // the buyer account row. Resolve the order owner without locking the
            // order, then acquire the shared buyer lock before any paid-state
            // mutation or order-row lock later in this transaction.
            if (Schema::hasTable('commerce_orders')) {
                $commerceBuyerId = DB::table('commerce_orders')
                    ->where('invoice_id', $invoice->id)
                    ->value('user_id');
                if ($commerceBuyerId !== null) {
                    User::query()->whereKey((int) $commerceBuyerId)->lockForUpdate()->firstOrFail();
                }
            }

            // A provider callback cannot settle an invoice in a different
            // currency, even when the numeric amount happens to fit.
            $transactionCurrency = strtoupper(trim((string) $lockedTx->currency));
            $invoiceCurrency = strtoupper(trim((string) $invoice->currency));
            if ($transactionCurrency === '' || $invoiceCurrency === '' || $transactionCurrency !== $invoiceCurrency) {
                throw new RuntimeException('Payment transaction currency does not match the invoice currency.');
            }
            if ((float) $lockedTx->amount <= 0) {
                throw new RuntimeException('Payment transaction amount must be greater than zero.');
            }

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
                && Schema::hasColumn('enrollments', 'invoice_id')
                && Schema::hasColumn('enrollments', 'branch_id')
                && Schema::hasTable('courses')
                && Schema::hasColumn('courses', 'branch_id')) {
                $pendingEnrollments = DB::table('enrollments')->where('invoice_id', $invoice->id)
                    ->where('status', 'pending')->lockForUpdate()->get();
                foreach ($pendingEnrollments as $pendingEnrollment) {
                    $courseBranchId = DB::table('courses')->where('id', $pendingEnrollment->course_id)->value('branch_id');
                    $enrollmentBranchId = $pendingEnrollment->branch_id !== null ? (int) $pendingEnrollment->branch_id : null;
                    $buyer = User::query()->find((int) $pendingEnrollment->user_id);
                    if (!$buyer) {
                        throw new RuntimeException('Pending course enrollment student account no longer exists.');
                    }
                    $assignedBranchId = $this->assignedBranchId($buyer);
                    $invoiceBranchId = $invoice->branch_id !== null ? (int) $invoice->branch_id : null;
                    if ($invoiceBranchId !== null && $assignedBranchId !== $invoiceBranchId) {
                        throw new RuntimeException('Student branch assignment changed after invoice creation; manual payment reconciliation is required.');
                    }
                    if ($courseBranchId !== null && ($enrollmentBranchId !== (int) $courseBranchId
                        || $assignedBranchId !== (int) $courseBranchId)) {
                        throw new RuntimeException('Pending course enrollment branch does not match the current student and source course.');
                    }
                    if ($enrollmentBranchId !== null && $assignedBranchId !== $enrollmentBranchId) {
                        throw new RuntimeException('Student branch assignment changed after invoice creation; manual payment reconciliation is required.');
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

            if ($newPaid >= (float) $invoice->net_amount && Schema::hasTable('commerce_orders')) {
                // Refuse to grant commerce access if the order, invoice, pending
                // enrollment, and source course disagree about branch ownership.
                $preflightOrders = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->get();
                foreach ($preflightOrders as $preflightOrder) {
                    // A settled invoice is not sufficient proof that a linked
                    // commerce order is internally consistent. Validate the
                    // persisted order snapshot before granting any entitlement.
                    $orderCurrency = strtoupper(trim((string) ($preflightOrder->currency ?? '')));
                    $invoiceCurrency = strtoupper(trim((string) $invoice->currency));
                    if ($orderCurrency === '' || $orderCurrency !== $invoiceCurrency) {
                        throw new RuntimeException('Commerce order currency does not match the invoice currency.');
                    }
                    if (abs(round((float) $preflightOrder->grand_total, 2) - round((float) $invoice->net_amount, 2)) > 0.01) {
                        throw new RuntimeException('Commerce order total does not match the invoice amount.');
                    }
                    $orderSubtotal = round((float) $preflightOrder->subtotal, 2);
                    $orderDiscount = round((float) $preflightOrder->discount_total, 2);
                    $orderGrandTotal = round((float) $preflightOrder->grand_total, 2);
                    if ($orderSubtotal <= 0 || $orderDiscount < 0 || $orderDiscount > $orderSubtotal
                        || abs(round($orderSubtotal - $orderDiscount, 2) - $orderGrandTotal) > 0.01) {
                        throw new RuntimeException('Commerce order subtotal, discount, and total do not reconcile.');
                    }
                    // Older installations can have invoice-linked commerce
                    // orders created before the unified order-items table existed.
                    // Preserve that legacy settlement path, but whenever the
                    // unified schema is installed require a non-empty, reconciled
                    // item snapshot before any entitlement can be granted.
                    if (Schema::hasTable('commerce_order_items')) {
                        $settlementItems = DB::table('commerce_order_items')
                            ->where('order_id', $preflightOrder->id)
                            ->lockForUpdate()
                            ->get();
                        if ($settlementItems->isEmpty()) {
                            throw new RuntimeException('An empty commerce order cannot be settled.');
                        }
                        foreach ($settlementItems as $settlementItem) {
                            // Gift purchases carry a recipient, redemption token and
                            // independent fulfillment lifecycle. They must never pass
                            // through the buyer-entitlement path below. Keep the
                            // generic settlement allow-list explicit until dedicated
                            // recipient fulfillment is linked to these order items.
                            $genericFulfillmentTypes = [
                                'course',
                                'ebook',
                                'mock_test',
                                'exam',
                                'subscription',
                                'live_class',
                                'other',
                            ];
                            if (in_array((string) $settlementItem->item_type, ['gift_course', 'gift_voucher'], true)) {
                                $this->assertGiftOrderItemReady($settlementItem, $preflightOrder, $lockedTx, $invoice);
                            } elseif (!in_array((string) $settlementItem->item_type, $genericFulfillmentTypes, true)) {
                                throw new RuntimeException(
                                    'Gift and voucher items require dedicated recipient fulfillment and cannot use buyer entitlements.'
                                );
                            }

                            $quantity = (int) $settlementItem->quantity;
                            $unitPrice = round((float) $settlementItem->unit_price, 2);
                            $lineTotal = round((float) $settlementItem->line_total, 2);
                            if ($quantity < 1 || $unitPrice < 0 || $lineTotal < 0
                                || abs(round($unitPrice * $quantity, 2) - $lineTotal) > 0.01) {
                                throw new RuntimeException('A commerce order item quantity, unit price, and line total do not reconcile.');
                            }
                        }
                        $itemsSubtotal = round($settlementItems->sum(fn ($item) => (float) $item->line_total), 2);
                        if (abs($itemsSubtotal - $orderSubtotal) > 0.01) {
                            throw new RuntimeException('Commerce order items do not reconcile with the order subtotal.');
                        }
                    }

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

                        $assignedBranchId = $this->assignedBranchId($buyer);
                        if ($assignedBranchId !== $orderBranchId) {
                            throw new RuntimeException('Student branch assignment changed after checkout; manual payment reconciliation is required.');
                        }
                    }
                }
                $orders = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->get();
                foreach ($orders as $order) {
                    if ($order->payment_status === 'paid') continue;

                    if (Schema::hasColumn('commerce_orders', 'coupon_reservation_key')
                        && filled($order->coupon_reservation_key)) {
                        if (!app(PluginManager::class)->isEnabled('marketing-management')
                            || !Schema::hasTable('promo_coupon_redemptions')) {
                            throw new RuntimeException('The marketing coupon module required by this order is unavailable.');
                        }
                        // CourseCouponService::redeem owns the coupon-first lock order.
                        // Do not lock the redemption row here before it locks the coupon.
                        $reservation = DB::table('promo_coupon_redemptions')
                            ->where('reservation_key', $order->coupon_reservation_key)
                            ->first();
                        if (!$reservation
                            || (int) $reservation->user_id !== (int) $order->user_id
                            || (int) $reservation->coupon_id !== (int) ($order->coupon_id ?? 0)
                            || abs((float) $reservation->discount_amount - (float) $order->discount_total) > 0.01) {
                            throw new RuntimeException('The course coupon reservation does not match the commerce order.');
                        }
                        app(CourseCouponService::class)->redeem((string) $order->coupon_reservation_key);
                    }

                    DB::table('commerce_orders')->where('id', $order->id)->update([
                        'status' => 'completed', 'payment_status' => 'paid', 'paid_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!Schema::hasTable('commerce_order_items') || !Schema::hasTable('commerce_entitlements')) continue;
                    $orderItems = DB::table('commerce_order_items')->where('order_id', $order->id)->get();
                    foreach ($orderItems as $orderItem) {
                        if ($orderItem->item_type === 'gift_course') {
                            $this->fulfillUnifiedCourseGift($orderItem, $order, $invoice, $lockedTx);
                            continue;
                        }
                        if ($orderItem->item_type === 'gift_voucher') {
                            $this->fulfillUnifiedGiftVoucher($orderItem, $order, $invoice, $lockedTx);
                            continue;
                        }

                        $itemDetails = json_decode((string) ($orderItem->metadata ?? '{}'), true) ?: [];
                        $entitlementDetails = $itemDetails['entitlement'] ?? $itemDetails;
                        $durationDays = isset($entitlementDetails['duration_days'])
                            ? max(1, min(3650, (int) $entitlementDetails['duration_days']))
                            : ($orderItem->item_type === 'subscription' ? 30 : null);
                        DB::table('commerce_entitlements')->updateOrInsert(
                            ['user_id' => $order->user_id, 'order_item_id' => $orderItem->id],
                            [
                                'entitlement_type' => $orderItem->item_type,
                                'resource_id' => $itemDetails['resource_id'] ?? $orderItem->item_id,
                                'details' => $orderItem->metadata,
                                'status' => 'active',
                                'starts_at' => now(),
                                'expires_at' => $durationDays ? now()->addDays($durationDays) : null,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
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
                            DB::table('storefront_item_access')->updateOrInsert(
                                ['storefront_item_id' => $orderItem->item_id, 'user_id' => $order->user_id],
                                [
                                    'payment_transaction_id' => $lockedTx->id,
                                    'granted_at' => now(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            );
                        }
                    }
                }
            }


            // Core order fulfillment must continue when the optional referral
            // plugin is disabled; only commission recording is plugin-gated.
            if ($newPaid >= (float) $invoice->net_amount && Schema::hasTable('commerce_orders')) {
                $order = DB::table('commerce_orders')->where('invoice_id', $invoice->id)->lockForUpdate()->first();
                if ($order) {
                    // Core commerce settlement already completed this order. The
                    // optional referral plugin must not control order lifecycle.
                    if (app(PluginManager::class)->isEnabled('referral-income')) {
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
            }

            $this->audit->log('payment.settled', 'payment_transaction', (string) $lockedTx->id, [
                'gateway' => $lockedTx->gateway_slug,
                'amount' => $lockedTx->amount,
                'invoice_id' => $invoice->id,
            ], $actorId);

            return $lockedTx->fresh();
        });
    }

    /**
     * Validate a gift draft against its immutable cart/order item before the
     * invoice can be considered settled. Gifts never use buyer entitlements.
     */
    private function assertGiftOrderItemReady(
        object $item,
        object $order,
        PaymentTransaction $transaction,
        FeeInvoice $invoice
    ): void {
        $unsupported = 'Gift and voucher items require dedicated recipient fulfillment and cannot use buyer entitlements.';
        $itemType = (string) $item->item_type;
        $table = $itemType === 'gift_course' ? 'course_gifts' : 'course_gift_vouchers';
        if (!Schema::hasTable($table)
            || !Schema::hasColumn($table, 'commerce_order_item_id')
            || !Schema::hasColumn($table, 'unified_payment_transaction_id')) {
            throw new RuntimeException($unsupported);
        }

        $draft = DB::table($table)->where('id', (int) $item->item_id)->lockForUpdate()->first();
        if (!$draft
            || (int) $draft->sender_user_id !== (int) $order->user_id
            || (int) ($draft->commerce_order_item_id ?? 0) !== (int) $item->id
            || $draft->status !== 'awaiting_payment'
            || $draft->invoice_id !== null
            || $draft->payment_transaction_id !== null
            || $draft->unified_payment_transaction_id !== null) {
            throw new RuntimeException($unsupported);
        }
        if ($draft->expires_at && now()->greaterThan($draft->expires_at)) {
            throw new RuntimeException('Gift draft expired before payment settlement; manual reconciliation is required.');
        }

        $recipientEmail = strtolower(trim((string) $draft->recipient_email));
        $senderEmail = strtolower(trim((string) DB::table('users')->where('id', $order->user_id)->value('email')));
        if ($recipientEmail === '' || $recipientEmail === $senderEmail) {
            throw new RuntimeException('Gift recipient must be different from the sender and have a valid email address.');
        }
        if (Schema::hasColumn($table, 'recipient_user_id') && $draft->recipient_user_id !== null) {
            $recipient = DB::table('users')->where('id', $draft->recipient_user_id)->first();
            if (!$recipient || strtolower(trim((string) $recipient->email)) !== $recipientEmail) {
                throw new RuntimeException('Gift recipient account no longer matches the verified recipient email.');
            }
        }

        $lineTotal = round((float) $item->line_total, 2);
        $amount = round((float) $draft->amount, 2);
        $currency = strtoupper(trim((string) $draft->currency));
        if ((int) $item->quantity !== 1 || $lineTotal <= 0
            || abs($amount - $lineTotal) > 0.01
            || $currency === ''
            || $currency !== strtoupper((string) $invoice->currency)
            || $currency !== strtoupper((string) $transaction->currency)
            || (int) $order->branch_id !== (int) $invoice->branch_id
            || (int) $order->branch_id !== (int) $transaction->branch_id) {
            throw new RuntimeException('Gift draft amount, currency, quantity, or branch snapshot does not match the settled order.');
        }

        if ($itemType === 'gift_course') {
            if (!Schema::hasTable('courses') || !Schema::hasColumn('course_gifts', 'course_id')
                || !Schema::hasColumn('course_gifts', 'redeem_token_hash')
                || !Schema::hasColumn('course_gifts', 'redeem_token_encrypted')
                || !Schema::hasColumn('course_gifts', 'subtotal_amount')
                || !Schema::hasColumn('course_gifts', 'discount_amount')) {
                throw new RuntimeException($unsupported);
            }
            $giftSubtotal = round((float) $draft->subtotal_amount, 2);
            $giftDiscount = round((float) $draft->discount_amount, 2);
            if ($giftSubtotal <= 0 || $giftDiscount < 0 || $giftDiscount > $giftSubtotal
                || abs(round($giftSubtotal - $giftDiscount, 2) - $amount) > 0.01
                || !filled($draft->redeem_token_hash)
                || !filled($draft->redeem_token_encrypted)) {
                throw new RuntimeException('Course gift amount, discount, or redemption token does not reconcile.');
            }
            $course = DB::table('courses')->where('id', (int) $draft->course_id)
                ->where('status', 'published')->first();
            if (!$course) {
                throw new RuntimeException('The gifted course is no longer published; manual reconciliation is required.');
            }
            $courseBranchId = Schema::hasColumn('courses', 'branch_id') && $course->branch_id !== null
                ? (int) $course->branch_id
                : null;
            $orderBranchId = $order->branch_id !== null ? (int) $order->branch_id : null;
            if ($courseBranchId !== null && $courseBranchId !== $orderBranchId) {
                throw new RuntimeException('Gifted course branch no longer matches the sender branch; manual reconciliation is required.');
            }
            if (Schema::hasColumn('course_gifts', 'recipient_user_id') && $draft->recipient_user_id !== null) {
                $recipient = User::query()->find((int) $draft->recipient_user_id);
                if (!$recipient || strtolower(trim((string) $recipient->email)) !== $recipientEmail) {
                    throw new RuntimeException('The known gift recipient account no longer matches the recipient email.');
                }
                if ($courseBranchId !== null && $this->assignedBranchId($recipient) !== $courseBranchId) {
                    throw new RuntimeException('The known gift recipient is not assigned to the course branch; manual reconciliation is required.');
                }
            }
        } else {
            foreach (['course_gift_wallets', 'course_gift_wallet_transactions', 'course_gift_voucher_redemptions'] as $requiredTable) {
                if (!Schema::hasTable($requiredTable)) {
                    throw new RuntimeException('Gift voucher wallet and redemption storage is not installed.');
                }
            }
            if (!filled($draft->redeem_token_hash) || !filled($draft->redeem_token_encrypted)
                || abs(round((float) $draft->remaining_amount, 2) - $amount) > 0.01
                || ($draft->expires_at && now()->greaterThan($draft->expires_at))) {
                throw new RuntimeException('Gift voucher balance or expiry does not match the payable draft.');
            }
        }
    }

    private function fulfillUnifiedCourseGift(
        object $item,
        object $order,
        FeeInvoice $invoice,
        PaymentTransaction $transaction
    ): void {
        $updated = DB::table('course_gifts')->where('id', (int) $item->item_id)
            ->where('sender_user_id', (int) $order->user_id)
            ->where('commerce_order_item_id', (int) $item->id)
            ->where('status', 'awaiting_payment')
            ->whereNull('invoice_id')
            ->whereNull('unified_payment_transaction_id')
            ->update([
                'invoice_id' => $invoice->id,
                'unified_payment_transaction_id' => $transaction->id,
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Course gift could not be issued exactly once after verified settlement.');
        }

        SendGiftRecipientEmailJob::dispatch('gift_course', (int) $item->item_id)->afterCommit();
    }

    private function fulfillUnifiedGiftVoucher(
        object $item,
        object $order,
        FeeInvoice $invoice,
        PaymentTransaction $transaction
    ): void {
        $updated = DB::table('course_gift_vouchers')->where('id', (int) $item->item_id)
            ->where('sender_user_id', (int) $order->user_id)
            ->where('commerce_order_item_id', (int) $item->id)
            ->where('status', 'awaiting_payment')
            ->whereNull('invoice_id')
            ->whereNull('unified_payment_transaction_id')
            ->update([
                'invoice_id' => $invoice->id,
                'unified_payment_transaction_id' => $transaction->id,
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Gift voucher could not be issued exactly once after verified settlement.');
        }

        SendGiftRecipientEmailJob::dispatch('gift_voucher', (int) $item->item_id)->afterCommit();
    }

    private function assignedBranchId(User $user): ?int
    {
        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $user->id)->value('branch_id');
            if ($profileBranchId !== null) {
                return (int) $profileBranchId;
            }
        }

        return Schema::hasColumn('users', 'branch_id') && $user->branch_id !== null
            ? (int) $user->branch_id
            : null;
    }
}
