<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Services;

use App\Core\Plugins\PluginManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry;
use RuntimeException;

class CourseCommercePaymentSettlementService
{
    private const TERMINAL_PAYMENT_STATUSES = ['failed', 'cancelled', 'refunded', 'reversed', 'rejected', 'chargeback'];
    public function __construct(private readonly PluginManager $plugins) {}

    public function settle(UniversalPaymentTransaction $transaction, string $validationId, array $providerDetails): UniversalPaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $validationId, $providerDetails): UniversalPaymentTransaction {
            $locked = UniversalPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::TERMINAL_PAYMENT_STATUSES, true)) {
                throw new RuntimeException('A terminal course-commerce payment cannot be settled again.');
            }
            if ($locked->status === 'refunded') {
                throw new RuntimeException('A refunded course-commerce transaction cannot be settled again.');
            }
            if ($locked->status === 'verified') {
                return $locked;
            }
            if (!in_array($locked->status, ['pending', 'risk_review'], true)) {
                throw new RuntimeException('Course commerce payment is not in a payable state.');
            }

            if (!$locked->user_id || $locked->gateway !== 'sslcommerz') {
                throw new RuntimeException('Course commerce payment is not linked to a valid student and gateway.');
            }

            // Use the same buyer-row serialization key as referral-code
            // attribution. Acquire it before locking or mutating course-cart,
            // gift or voucher orders so a referral cannot be applied after the
            // buyer's first paid commerce settlement has committed.
            User::query()->whereKey((int) $locked->user_id)->lockForUpdate()->firstOrFail();

            $notes = json_decode((string) $locked->notes, true);
            if (!is_array($notes)) {
                throw new RuntimeException('Course commerce payment context is missing.');
            }

            $purpose = $notes['purpose'] ?? null;
            if ($purpose === 'course_cart') {
                $this->settleCart($locked, $notes);
            } elseif ($purpose === 'course_gift') {
                $this->settleGift($locked, $notes);
            } elseif ($purpose === 'course_gift_voucher') {
                $this->settleVoucher($locked, $notes);
            } else {
                throw new RuntimeException('Unsupported course commerce payment purpose.');
            }

            app(ExternalTransactionReferenceRegistry::class)->claim(
                (string) $locked->gateway,
                $validationId,
                (int) $locked->id
            );

            $locked->update([
                'status' => 'verified',
                'external_trx_id' => $validationId,
                'verified_at' => now(),
                'notes' => json_encode(array_merge($notes, $providerDetails, [
                    'provider' => 'sslcommerz',
                    'settlement' => 'completed',
                ]), JSON_THROW_ON_ERROR),
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function settleCart(UniversalPaymentTransaction $transaction, array $notes): void
    {
        $orderId = (int) ($notes['course_cart_order_id'] ?? 0);
        $order = DB::table('course_cart_orders')->where('id', $orderId)->lockForUpdate()->first();
        if (!$order || (int) $order->user_id !== (int) $transaction->user_id
            || (int) ($order->payment_transaction_id ?? 0) !== (int) $transaction->id) {
            throw new RuntimeException('Course cart order does not match the payment transaction.');
        }
        if (($order->coupon_reservation_key ?? null) !== ($notes['coupon_reservation_key'] ?? null)) {
            throw new RuntimeException('Course cart coupon reservation does not match the payment context.');
        }
        if ($order->status === 'paid') {
            throw new RuntimeException('Course cart order is already settled by another payment.');
        }
        if (!in_array($order->status, ['awaiting_payment', 'risk_review'], true)) {
            throw new RuntimeException('Course cart order is not payable.');
        }

        $walletCredit = round((float) ($order->wallet_credit_amount ?? 0), 2);
        $expectedGatewayAmount = round(max(0, (float) $order->net_amount - $walletCredit), 2);
        if (strtoupper((string) $order->currency) !== strtoupper((string) $transaction->currency)
            || abs($expectedGatewayAmount - (float) $transaction->amount) > 0.01) {
            throw new RuntimeException('Course cart amount or currency does not match the verified payment.');
        }

        $items = DB::table('course_cart_order_items')->where('order_id', $orderId)->orderBy('position')->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('Course cart order has no item snapshots.');
        }
        $courseIds = $items->pluck('course_id')->map(fn ($id): int => (int) $id)->all();
        $courses = DB::table('courses')->whereIn('id', $courseIds)->where('status', 'published')->get()->keyBy('id');
        if ($courses->count() !== count($courseIds)) {
            throw new RuntimeException('A purchased course is no longer available; manual reconciliation is required.');
        }

        $orderBranchId = $order->branch_id !== null ? (int) $order->branch_id : null;
        $transactionBranchId = $transaction->branch_id !== null ? (int) $transaction->branch_id : null;
        $this->assertSettlementBranchAccess(
            (int) $transaction->user_id,
            $orderBranchId,
            $transactionBranchId,
            $courses->values()
        );

        $invoice = $this->createPaidInvoice(
            $transaction,
            (int) ($order->branch_id ?? $transaction->branch_id ?? 0) ?: null,
            (float) $order->subtotal_amount,
            (float) $order->discount_amount,
            (float) $order->net_amount,
            (string) $order->currency,
            'CART'
        );

        foreach ($items as $item) {
            $existing = DB::table('enrollments')->where('user_id', $transaction->user_id)
                ->where('course_id', $item->course_id)->whereIn('status', ['pending', 'active', 'completed'])->exists();
            if ($existing) {
                throw new RuntimeException('Course access already exists under another enrollment; manual reconciliation is required.');
            }
            DB::table('enrollments')->insert([
                'user_id' => $transaction->user_id,
                'course_id' => $item->course_id,
                'branch_id' => $order->branch_id ?? $transaction->branch_id,
                'invoice_id' => $invoice->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (filled($order->coupon_reservation_key)) {
            app(CourseCouponService::class)->redeem((string) $order->coupon_reservation_key);
        }

        if (filled($order->wallet_reservation_key)) {
            $walletEntry = DB::table('course_gift_wallet_transactions')
                ->where('reference', $order->wallet_reservation_key)->lockForUpdate()->first();
            if (!$walletEntry || $walletEntry->status !== 'reserved'
                || abs((float) $walletEntry->amount - $walletCredit) > 0.01) {
                throw new RuntimeException('Gift wallet reservation could not be reconciled.');
            }
            DB::table('course_gift_wallet_transactions')->where('id', $walletEntry->id)
                ->update(['status' => 'completed', 'updated_at' => now()]);
        }

        DB::table('course_cart_orders')->where('id', $orderId)->update([
            'invoice_id' => $invoice->id,
            'status' => 'paid',
            'paid_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_carts')->where('id', $order->cart_id)->update([
            'status' => 'completed',
            'updated_at' => now(),
        ]);

        if ($this->plugins->isEnabled('referral-income')) {
            $buyer = User::query()->find((int) $transaction->user_id);
            if ($buyer) {
                app(ReferralIncomeService::class)->recordSettledPurchase(
                    $buyer,
                    'course_cart_order',
                    (string) $orderId,
                    (float) $order->net_amount,
                    (string) $order->currency
                );
            }
        }
    }

    private function settleGift(UniversalPaymentTransaction $transaction, array $notes): void
    {
        $giftId = (int) ($notes['course_gift_id'] ?? 0);
        $gift = DB::table('course_gifts')->where('id', $giftId)->lockForUpdate()->first();
        if (!$gift || (int) $gift->sender_user_id !== (int) $transaction->user_id
            || (int) ($gift->payment_transaction_id ?? 0) !== (int) $transaction->id) {
            throw new RuntimeException('Course gift does not match the payment transaction.');
        }
        if (($gift->coupon_reservation_key ?? null) !== ($notes['coupon_reservation_key'] ?? null)) {
            throw new RuntimeException('Course gift coupon reservation does not match the payment context.');
        }
        if ($gift->status === 'paid') {
            throw new RuntimeException('Course gift is already settled by another payment.');
        }
        if ($gift->expires_at && now()->greaterThan($gift->expires_at)) {
            throw new RuntimeException('Course gift draft expired before payment settlement; manual reconciliation is required.');
        }
        if ($gift->status !== 'awaiting_payment'
            || strtoupper((string) $gift->currency) !== strtoupper((string) $transaction->currency)
            || abs((float) $gift->amount - (float) $transaction->amount) > 0.01) {
            throw new RuntimeException('Course gift amount, currency or status does not match the verified payment.');
        }

        $course = DB::table('courses')->where('id', $gift->course_id)->where('status', 'published')->first();
        if (!$course) {
            throw new RuntimeException('Gifted course is no longer available; manual reconciliation is required.');
        }

        $giftBranchId = $transaction->branch_id !== null ? (int) $transaction->branch_id : null;
        $this->assertSettlementBranchAccess((int) $transaction->user_id, $giftBranchId, $giftBranchId, [$course]);

        $subtotal = round((float) ($gift->subtotal_amount ?? $gift->amount), 2);
        $discount = round((float) ($gift->discount_amount ?? 0), 2);
        if (abs(round($subtotal - $discount, 2) - (float) $gift->amount) > 0.01) {
            throw new RuntimeException('Gift coupon totals do not match the paid amount.');
        }
        if (filled($gift->coupon_reservation_key)) {
            app(CourseCouponService::class)->redeem((string) $gift->coupon_reservation_key);
        }

        $invoice = $this->createPaidInvoice(
            $transaction,
            (int) ($course->branch_id ?? $transaction->branch_id ?? 0) ?: null,
            $subtotal,
            $discount,
            (float) $gift->amount,
            (string) $gift->currency,
            'GIFT'
        );

        DB::table('course_gifts')->where('id', $giftId)->update([
            'invoice_id' => $invoice->id,
            'status' => 'paid',
            'paid_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settleVoucher(UniversalPaymentTransaction $transaction, array $notes): void
    {
        $voucherId = (int) ($notes['course_gift_voucher_id'] ?? 0);
        $voucher = DB::table('course_gift_vouchers')->where('id', $voucherId)->lockForUpdate()->first();
        if (!$voucher || (int) $voucher->sender_user_id !== (int) $transaction->user_id
            || (int) ($voucher->payment_transaction_id ?? 0) !== (int) $transaction->id) {
            throw new RuntimeException('Gift voucher does not match the payment transaction.');
        }
        $voucherBranchId = $transaction->branch_id !== null ? (int) $transaction->branch_id : null;
        $this->assertSettlementBranchAccess((int) $transaction->user_id, $voucherBranchId, $voucherBranchId, []);

        if ($voucher->status === 'paid') {
            throw new RuntimeException('Gift voucher is already settled by another payment.');
        }
        if ($voucher->status !== 'awaiting_payment'
            || strtoupper((string) $voucher->currency) !== strtoupper((string) $transaction->currency)
            || abs((float) $voucher->amount - (float) $transaction->amount) > 0.01) {
            throw new RuntimeException('Gift voucher amount, currency or status does not match the verified payment.');
        }

        $invoice = $this->createPaidInvoice(
            $transaction,
            $transaction->branch_id !== null ? (int) $transaction->branch_id : null,
            (float) $voucher->amount,
            0,
            (float) $voucher->amount,
            (string) $voucher->currency,
            'VOUCHER'
        );

        DB::table('course_gift_vouchers')->where('id', $voucherId)->update([
            'invoice_id' => $invoice->id,
            'status' => 'paid',
            'paid_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Re-check branch ownership at callback time. A student or course can be
     * reassigned after checkout but before the payment provider calls back.
     * Never activate access or issue an invoice against a stale branch snapshot.
     *
     * @param iterable<object> $courses
     */
    private function assertSettlementBranchAccess(
        int $userId,
        ?int $orderBranchId,
        ?int $transactionBranchId,
        iterable $courses
    ): void {
        if ($orderBranchId !== $transactionBranchId) {
            throw new RuntimeException('Course branch or student assignment changed after checkout; manual reconciliation is required.');
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            throw new RuntimeException('Course commerce student account no longer exists.');
        }

        $assignedBranchId = null;
        if ($this->plugins->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $userId)->value('branch_id');
            if ($profileBranchId !== null) {
                $assignedBranchId = (int) $profileBranchId;
            }
        }

        if ($assignedBranchId === null && Schema::hasColumn('users', 'branch_id') && $user->branch_id !== null) {
            $assignedBranchId = (int) $user->branch_id;
        }

        if ($assignedBranchId !== $orderBranchId) {
            throw new RuntimeException('Course branch or student assignment changed after checkout; manual reconciliation is required.');
        }

        foreach ($courses as $course) {
            $courseBranchId = property_exists($course, 'branch_id') && $course->branch_id !== null
                ? (int) $course->branch_id
                : null;
            if ($courseBranchId !== null && $courseBranchId !== $orderBranchId) {
                throw new RuntimeException('Course branch or student assignment changed after checkout; manual reconciliation is required.');
            }
        }
    }

    private function createPaidInvoice(
        UniversalPaymentTransaction $transaction,
        ?int $branchId,
        float $subtotal,
        float $discount,
        float $total,
        string $currency,
        string $prefix
    ): FeeInvoice {
        $studentProfile = $this->plugins->isEnabled('student-management') && Schema::hasTable('students')
            ? DB::table('students')->where('user_id', $transaction->user_id)->first()
            : null;

        return FeeInvoice::query()->create([
            'invoice_number' => $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            'student_id' => $studentProfile->id ?? null,
            'payer_name' => $transaction->payer_name,
            'payer_email' => DB::table('users')->where('id', $transaction->user_id)->value('email'),
            'payer_phone' => $transaction->payer_contact,
            'branch_id' => $branchId,
            'subtotal_amount' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'net_amount' => round($total, 2),
            'paid_amount' => round($total, 2),
            'currency' => strtoupper($currency),
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
    }
}
