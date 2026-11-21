<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Services\CourseCommercePaymentSettlementService;
use Plugins\Modules\MarketingManagement\Models\PromoCoupon;
use Plugins\Modules\MarketingManagement\Services\CourseCouponService;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Tests\TestCase;

class CourseCommercePaymentSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management', 'marketing-management', 'universal-payment']);
    }

    public function test_verified_course_cart_payment_activates_enrollment_and_is_idempotent(): void
    {
        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'SETTLE-001',
            'title' => 'Settlement Test Course',
            'slug' => 'settlement-test-course',
            'price' => 1250,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $student->id,
            'status' => 'checkout_pending',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $student->id,
            'branch_id' => null,
            'subtotal_amount' => 1250,
            'discount_amount' => 0,
            'net_amount' => 1250,
            'wallet_credit_amount' => 0,
            'currency' => 'BDT',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_order_items')->insert([
            'order_id' => $orderId,
            'course_id' => $courseId,
            'course_title' => 'Settlement Test Course',
            'unit_price' => 1250,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($student->id, 1250, [
            'purpose' => 'course_cart',
            'course_cart_order_id' => $orderId,
        ]);
        DB::table('course_cart_orders')->where('id', $orderId)->update(['payment_transaction_id' => $transaction->id]);

        $lockSequence = [];
        DB::listen(function ($query) use (&$lockSequence): void {
            $sql = strtolower(preg_replace('/\\s+/', ' ', $query->sql));
            if (str_contains($sql, 'users') && str_contains($sql, 'for update')) {
                $lockSequence[] = 'buyer';
            }
            if (str_contains($sql, 'course_cart_orders') && str_contains($sql, 'for update')) {
                $lockSequence[] = 'order';
            }
        });

        $service = app(CourseCommercePaymentSettlementService::class);
        $settled = $service->settle($transaction, 'BANK-CART-SETTLE-001', ['validation_status' => 'VALID']);
        if (DB::connection()->getDriverName() === 'mysql') {
            $buyerLock = array_search('buyer', $lockSequence, true);
            $orderLock = array_search('order', $lockSequence, true);
            $this->assertNotFalse($buyerLock, 'Course commerce settlement must lock the buyer account.');
            $this->assertNotFalse($orderLock, 'Course commerce settlement must lock the order.');
            $this->assertLessThan($orderLock, $buyerLock, 'Buyer lock must precede the paid order lock.');
        }
        $service->settle($settled, 'BANK-CART-SETTLE-001', ['validation_status' => 'VALID']);

        $this->assertSame('verified', $settled->fresh()->status);
        $this->assertDatabaseHas('course_cart_orders', ['id' => $orderId, 'status' => 'paid']);
        $this->assertDatabaseHas('course_carts', ['id' => $cartId, 'status' => 'completed']);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $courseId, 'status' => 'active']);
        $this->assertSame(1, DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $courseId)->count());
        $this->assertSame(1, DB::table('fee_invoices')->where('payer_email', $student->email)->count());
    }

    public function test_verified_course_gift_payment_creates_invoice_and_marks_gift_paid_once(): void
    {
        $sender = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'GIFT-SETTLE-001',
            'title' => 'Gift Settlement Course',
            'slug' => 'gift-settlement-course',
            'price' => 800,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $coupon = PromoCoupon::query()->create([
            'code' => 'GIFTSETTLE10',
            'discount_percent' => 10,
            'max_uses' => 5,
            'used_count' => 0,
            'expires_at' => now()->addDays(7)->toDateString(),
            'is_active' => true,
            'discount_type' => 'percent',
            'minimum_subtotal' => 0,
            'applies_to_course_ids' => [$courseId],
            'first_purchase_only' => false,
            'gift_only' => true,
        ]);
        $reservation = app(CourseCouponService::class)->reserve($coupon->code, (int) $sender->id, [$courseId], 800, true);
        $token = str_repeat('T', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => 'gift-settlement@example.test',
            'course_id' => $courseId,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'awaiting_payment',
            'currency' => 'BDT',
            'amount' => 720,
            'subtotal_amount' => 800,
            'discount_amount' => 80,
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'coupon_reservation_key' => $reservation['reservation_key'],
            'expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($sender->id, 720, [
            'purpose' => 'course_gift',
            'course_gift_id' => $giftId,
            'coupon_reservation_key' => $reservation['reservation_key'],
        ]);
        DB::table('course_gifts')->where('id', $giftId)->update(['payment_transaction_id' => $transaction->id]);

        $service = app(CourseCommercePaymentSettlementService::class);
        $service->settle($transaction, 'BANK-GIFT-SETTLE-001', ['validation_status' => 'VALID']);
        $service->settle($transaction->fresh(), 'BANK-GIFT-SETTLE-001', ['validation_status' => 'VALID']);

        $gift = DB::table('course_gifts')->where('id', $giftId)->first();
        $this->assertSame('paid', $gift->status);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
        $this->assertDatabaseHas('promo_coupon_redemptions', ['reservation_key' => $reservation['reservation_key'], 'status' => 'redeemed']);
        $this->assertNotNull($gift->invoice_id);
        $this->assertDatabaseHas('fee_invoices', ['id' => $gift->invoice_id, 'status' => 'paid']);
        $this->assertSame(0, DB::table('enrollments')->where('course_id', $courseId)->count());
        $this->assertSame(1, DB::table('fee_invoices')->where('payer_email', $sender->email)->count());
    }

    public function test_expired_course_gift_draft_cannot_be_settled_by_a_late_callback(): void
    {
        $sender = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'GIFT-EXPIRED-SETTLE-001',
            'title' => 'Expired Gift Settlement Course',
            'slug' => 'expired-gift-settlement-course',
            'price' => 800,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = str_repeat('X', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => 'expired-gift-settlement@example.test',
            'course_id' => $courseId,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'awaiting_payment',
            'currency' => 'BDT',
            'amount' => 800,
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subDays(91),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($sender->id, 800, [
            'purpose' => 'course_gift',
            'course_gift_id' => $giftId,
        ]);
        DB::table('course_gifts')->where('id', $giftId)->update(['payment_transaction_id' => $transaction->id]);

        try {
            app(CourseCommercePaymentSettlementService::class)->settle(
                $transaction,
                'BANK-GIFT-EXPIRED-001',
                ['validation_status' => 'VALID']
            );
            $this->fail('A late payment callback must not settle an expired course gift.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Course gift draft expired before payment settlement; manual reconciliation is required.', $exception->getMessage());
        }

        $this->assertDatabaseHas('course_gifts', [
            'id' => $giftId,
            'status' => 'awaiting_payment',
            'invoice_id' => null,
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
            'verified_at' => null,
        ]);
    }

    public function test_gift_voucher_settlement_rejects_sender_branch_changes_after_checkout(): void
    {
        $sender = $this->createUserWithRole('student');
        $originalBranchId = 610;
        DB::table('users')->where('id', $sender->id)->update(['branch_id' => $originalBranchId]);

        $token = str_repeat('B', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => 'voucher-branch@example.test',
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'currency' => 'BDT',
            'amount' => 1500,
            'remaining_amount' => 1500,
            'status' => 'awaiting_payment',
            'expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($sender->id, 1500, [
            'purpose' => 'course_gift_voucher',
            'course_gift_voucher_id' => $voucherId,
        ]);
        $transaction->update(['branch_id' => $originalBranchId]);
        DB::table('course_gift_vouchers')->where('id', $voucherId)->update(['payment_transaction_id' => $transaction->id]);

        DB::table('users')->where('id', $sender->id)->update(['branch_id' => $originalBranchId + 1]);

        try {
            app(CourseCommercePaymentSettlementService::class)->settle(
                $transaction->fresh(),
                'BANK-VOUCHER-BRANCH-001',
                ['validation_status' => 'VALID']
            );
            $this->fail('Voucher settlement must reject a sender whose branch changed after checkout.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Course branch or student assignment changed after checkout; manual reconciliation is required.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('course_gift_vouchers', [
            'id' => $voucherId,
            'status' => 'awaiting_payment',
            'invoice_id' => null,
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
        ]);
        $this->assertSame(0, DB::table('fee_invoices')->where('payer_email', $sender->email)->count());
    }

    public function test_verified_gift_voucher_payment_creates_a_paid_invoice(): void
    {
        $sender = $this->createUserWithRole('student');
        $token = str_repeat('W', 64);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => 'voucher-settlement@example.test',
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'currency' => 'BDT',
            'amount' => 1500,
            'remaining_amount' => 1500,
            'status' => 'awaiting_payment',
            'expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($sender->id, 1500, [
            'purpose' => 'course_gift_voucher',
            'course_gift_voucher_id' => $voucherId,
        ]);
        DB::table('course_gift_vouchers')->where('id', $voucherId)->update(['payment_transaction_id' => $transaction->id]);

        app(CourseCommercePaymentSettlementService::class)->settle($transaction, 'BANK-VOUCHER-SETTLE-001', ['validation_status' => 'VALID']);

        $voucher = DB::table('course_gift_vouchers')->where('id', $voucherId)->first();
        $this->assertSame('paid', $voucher->status);
        $this->assertNotNull($voucher->invoice_id);
        $this->assertDatabaseHas('fee_invoices', ['id' => $voucher->invoice_id, 'status' => 'paid']);
    }

    public function test_late_callback_cannot_resettle_a_refunded_course_cart_transaction(): void
    {
        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'SETTLE-REFUND-001',
            'title' => 'Refund Guard Test Course',
            'slug' => 'refund-guard-test-course',
            'price' => 1250,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $student->id,
            'status' => 'checkout_pending',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $student->id,
            'branch_id' => null,
            'subtotal_amount' => 1250,
            'discount_amount' => 0,
            'net_amount' => 1250,
            'wallet_credit_amount' => 0,
            'currency' => 'BDT',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_order_items')->insert([
            'order_id' => $orderId,
            'course_id' => $courseId,
            'course_title' => 'Refund Guard Test Course',
            'unit_price' => 1250,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($student->id, 1250, [
            'purpose' => 'course_cart',
            'course_cart_order_id' => $orderId,
        ]);
        $transaction->update(['status' => 'refunded']);
        DB::table('course_cart_orders')->where('id', $orderId)->update(['payment_transaction_id' => $transaction->id]);

        try {
            app(CourseCommercePaymentSettlementService::class)->settle(
                $transaction->fresh(),
                'BANK-LATE-REFUND-001',
                ['validation_status' => 'VALID']
            );
            $this->fail('A refunded course-commerce transaction must never be settled again.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('A terminal course-commerce payment cannot be settled again.', $exception->getMessage());
        }

        $this->assertDatabaseHas('universal_payment_transactions', ['id' => $transaction->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('course_cart_orders', ['id' => $orderId, 'status' => 'awaiting_payment']);
        $this->assertSame(0, DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $courseId)->count());
        $this->assertSame(0, DB::table('fee_invoices')->where('payer_email', $student->email)->count());
    }

    public function test_course_cart_settlement_rejects_branch_changes_after_checkout(): void
    {
        $student = $this->createUserWithRole('student');
        $studentBranchId = 501;
        $courseBranchId = 502;
        DB::table('users')->where('id', $student->id)->update(['branch_id' => $studentBranchId]);

        $courseId = DB::table('courses')->insertGetId([
            'code' => 'SETTLE-BRANCH-001',
            'title' => 'Branch Settlement Test Course',
            'slug' => 'branch-settlement-test-course',
            'price' => 1250,
            'status' => 'published',
            'branch_id' => $courseBranchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $student->id,
            'status' => 'checkout_pending',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $student->id,
            'branch_id' => $studentBranchId,
            'subtotal_amount' => 1250,
            'discount_amount' => 0,
            'net_amount' => 1250,
            'wallet_credit_amount' => 0,
            'currency' => 'BDT',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_order_items')->insert([
            'order_id' => $orderId,
            'course_id' => $courseId,
            'course_title' => 'Branch Settlement Test Course',
            'unit_price' => 1250,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($student->id, 1250, [
            'purpose' => 'course_cart',
            'course_cart_order_id' => $orderId,
        ]);
        $transaction->update(['branch_id' => $studentBranchId]);
        DB::table('course_cart_orders')->where('id', $orderId)->update(['payment_transaction_id' => $transaction->id]);

        try {
            app(CourseCommercePaymentSettlementService::class)->settle(
                $transaction->fresh(),
                'BANK-BRANCH-SETTLE-001',
                ['validation_status' => 'VALID']
            );
            $this->fail('Settlement must reject a course whose branch changed after checkout.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Course branch or student assignment changed after checkout; manual reconciliation is required.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('course_cart_orders', ['id' => $orderId, 'status' => 'awaiting_payment']);
        $this->assertDatabaseHas('universal_payment_transactions', ['id' => $transaction->id, 'status' => 'pending']);
        $this->assertSame(0, DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $courseId)->count());
        $this->assertSame(0, DB::table('fee_invoices')->where('payer_email', $student->email)->count());
    }

    private function transaction(int $userId, float $amount, array $notes): UniversalPaymentTransaction
    {
        return UniversalPaymentTransaction::query()->create([
            'reference' => 'TEST-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => 'Commerce Test Student',
            'payer_contact' => '01700000000',
            'item_title' => 'Course Commerce Test',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => $amount,
            'external_trx_id' => null,
            'status' => 'pending',
            'notes' => json_encode($notes, JSON_THROW_ON_ERROR),
            'user_id' => $userId,
            'branch_id' => null,
        ]);
    }
}
