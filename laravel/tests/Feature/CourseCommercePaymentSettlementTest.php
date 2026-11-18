<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Services\CourseCommercePaymentSettlementService;
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

        $service = app(CourseCommercePaymentSettlementService::class);
        $settled = $service->settle($transaction, 'BANK-CART-SETTLE-001', ['validation_status' => 'VALID']);
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
        $token = str_repeat('T', 64);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $sender->id,
            'recipient_email' => 'gift-settlement@example.test',
            'course_id' => $courseId,
            'redeem_token_hash' => hash('sha256', $token),
            'redeem_token_encrypted' => encrypt($token),
            'status' => 'awaiting_payment',
            'currency' => 'BDT',
            'amount' => 800,
            'expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = $this->transaction($sender->id, 800, [
            'purpose' => 'course_gift',
            'course_gift_id' => $giftId,
        ]);
        DB::table('course_gifts')->where('id', $giftId)->update(['payment_transaction_id' => $transaction->id]);

        $service = app(CourseCommercePaymentSettlementService::class);
        $service->settle($transaction, 'BANK-GIFT-SETTLE-001', ['validation_status' => 'VALID']);
        $service->settle($transaction->fresh(), 'BANK-GIFT-SETTLE-001', ['validation_status' => 'VALID']);

        $gift = DB::table('course_gifts')->where('id', $giftId)->first();
        $this->assertSame('paid', $gift->status);
        $this->assertNotNull($gift->invoice_id);
        $this->assertDatabaseHas('fee_invoices', ['id' => $gift->invoice_id, 'status' => 'paid']);
        $this->assertSame(0, DB::table('enrollments')->where('course_id', $courseId)->count());
        $this->assertSame(1, DB::table('fee_invoices')->where('payer_email', $sender->email)->count());
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
