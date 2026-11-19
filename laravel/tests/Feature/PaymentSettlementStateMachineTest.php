<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;
use Tests\TestCase;

class PaymentSettlementStateMachineTest extends TestCase
{

    public function test_commerce_order_is_fulfilled_when_referral_plugin_is_disabled(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);

        $buyer = $this->createUserWithRole('student');
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'NO-REFERRAL-ORDER-' . $buyer->id,
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'NOREF-' . $buyer->id,
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 500,
            'discount_total' => 0,
            'grand_total' => 500,
            'status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'course',
            'item_id' => 987654,
            'title_snapshot' => 'Referral-independent course',
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'metadata' => json_encode(['resource_id' => 987654], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'NO-REFERRAL-TXN-' . $buyer->id,
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 500,
            'currency' => 'BDT',
            'status' => 'initiated',
        ]);

        $settled = app(PaymentSettlementService::class)->settleTransaction(
            $transaction,
            'NO-REFERRAL-PROVIDER-' . $buyer->id,
        );

        $this->assertSame('settled', $settled->fresh()->status);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('commerce_entitlements', [
            'user_id' => $buyer->id,
            'order_item_id' => $itemId,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 500,
            'status' => 'paid',
        ]);
    }

    public function test_settlement_rejects_payment_currency_mismatch_without_mutating_invoice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'CURRENCY-GUARD-' . strtoupper(uniqid()),
            'payer_name' => 'Currency Guard Student',
            'payer_email' => 'currency.guard@aec.test',
            'subtotal_amount' => 1250,
            'discount_amount' => 0,
            'net_amount' => 1250,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'CURRENCY-GUARD-TXN-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 1250,
            'currency' => 'USD',
            'status' => 'initiated',
            'metadata' => [],
        ]);

        try {
            app(PaymentSettlementService::class)->settleTransaction(
                $transaction,
                'CURRENCY-MISMATCH-REFERENCE',
            );
            $this->fail('A payment in USD must not settle a BDT invoice.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Payment transaction currency does not match the invoice currency.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => 'initiated',
            'external_reference' => null,
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
    }

    public function test_partial_payments_and_duplicate_callbacks_are_idempotent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'PARTIAL-SETTLE-' . strtoupper(uniqid()),
            'payer_name' => 'Partial Payment Student',
            'payer_email' => 'partial.payment@aec.test',
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $first = PaymentTransaction::query()->create([
            'transaction_ref' => 'PARTIAL-TXN-A-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 400,
            'currency' => 'BDT',
            'status' => 'initiated',
            'metadata' => [],
        ]);

        $settlement = app(PaymentSettlementService::class);
        $settlement->settleTransaction($first, 'PARTIAL-PROVIDER-A');

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 400,
            'status' => 'partial',
        ]);

        // A repeated callback for an already settled transaction must not
        // credit the invoice again or overwrite the original provider ref.
        $settlement->settleTransaction($first->fresh(), 'DIFFERENT-DUPLICATE-REFERENCE');
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $first->id,
            'status' => 'settled',
            'external_reference' => 'PARTIAL-PROVIDER-A',
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 400,
            'status' => 'partial',
        ]);

        $second = PaymentTransaction::query()->create([
            'transaction_ref' => 'PARTIAL-TXN-B-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 600,
            'currency' => 'BDT',
            'status' => 'initiated',
            'metadata' => [],
        ]);
        $settlement->settleTransaction($second, 'PARTIAL-PROVIDER-B');

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);
    }

    public function test_settlement_rejects_overpayment_without_mutating_transaction_or_invoice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'OVERPAY-GUARD-' . strtoupper(uniqid()),
            'payer_name' => 'Overpayment Guard Student',
            'payer_email' => 'overpayment.guard@aec.test',
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'OVERPAY-TXN-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 500.02,
            'currency' => 'BDT',
            'status' => 'initiated',
            'metadata' => [],
        ]);

        try {
            app(PaymentSettlementService::class)->settleTransaction($transaction, 'OVERPAY-PROVIDER-REF');
            $this->fail('Settlement must reject a transaction above the invoice balance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Settlement exceeds remaining invoice balance.', $exception->getMessage());
        }

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => 'initiated',
            'external_reference' => null,
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
    }

    public function test_commerce_order_currency_and_total_must_match_invoice_before_fulfillment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);

        foreach (['currency', 'total', 'item_math', 'gift_course', 'gift_voucher'] as $case) {
            $buyer = $this->createUserWithRole('student');
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'ORDER-RECONCILE-' . strtoupper(uniqid()),
                'payer_name' => $buyer->name,
                'payer_email' => $buyer->email,
                'subtotal_amount' => 500,
                'discount_amount' => 0,
                'net_amount' => 500,
                'paid_amount' => 0,
                'currency' => 'BDT',
                'status' => 'unpaid',
            ]);
            $orderId = DB::table('commerce_orders')->insertGetId([
                'order_number' => 'ORDER-CHECK-' . strtoupper(uniqid()),
                'user_id' => $buyer->id,
                'invoice_id' => $invoice->id,
                'branch_id' => null,
                'currency' => $case === 'currency' ? 'USD' : 'BDT',
                'subtotal' => 500,
                'discount_total' => 0,
                'grand_total' => $case === 'total' ? 450 : 500,
                'status' => 'awaiting_payment',
                'payment_status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('commerce_order_items')->insert([
                'order_id' => $orderId,
                'item_type' => match ($case) {
                    'gift_course' => 'gift_course',
                    'gift_voucher' => 'gift_voucher',
                    default => 'course',
                },
                'item_id' => 987650 + $buyer->id,
                'title_snapshot' => 'Order reconciliation course',
                'quantity' => 1,
                'unit_price' => $case === 'item_math' ? 400 : 500,
                'line_total' => 500,
                'metadata' => json_encode(['resource_id' => 987650 + $buyer->id], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $transaction = PaymentTransaction::query()->create([
                'transaction_ref' => 'ORDER-CHECK-TXN-' . strtoupper(uniqid()),
                'invoice_id' => $invoice->id,
                'gateway_slug' => 'offline',
                'amount' => 500,
                'currency' => 'BDT',
                'status' => 'initiated',
                'metadata' => [],
            ]);

            try {
                app(PaymentSettlementService::class)->settleTransaction(
                    $transaction,
                    'ORDER-CHECK-PROVIDER-' . $case,
                );
                $this->fail('Inconsistent commerce order data must block fulfillment.');
            } catch (RuntimeException $exception) {
                $expected = match ($case) {
                    'currency' => 'Commerce order currency does not match the invoice currency.',
                    'total' => 'Commerce order total does not match the invoice amount.',
                     'item_math' => 'A commerce order item quantity, unit price, and line total do not reconcile.',
                    default => 'Gift and voucher items require dedicated recipient fulfillment and cannot use buyer entitlements.',
                };
                $this->assertSame($expected, $exception->getMessage());
            }

            $this->assertDatabaseHas('payment_transactions', [
                'id' => $transaction->id,
                'status' => 'initiated',
                'external_reference' => null,
            ]);
            $this->assertDatabaseHas('fee_invoices', [
                'id' => $invoice->id,
                'paid_amount' => 0,
                'status' => 'unpaid',
            ]);
            $this->assertDatabaseHas('commerce_orders', [
                'id' => $orderId,
                'status' => 'awaiting_payment',
                'payment_status' => 'unpaid',
            ]);
        }

        $this->assertDatabaseCount('commerce_entitlements', 0);
    }

    public function test_mixed_course_and_gift_orders_fail_closed_before_any_buyer_entitlement(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);

        foreach (['gift_course', 'gift_voucher'] as $giftType) {
            $buyer = $this->createUserWithRole('student');
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'MIXED-GIFT-' . strtoupper(uniqid()),
                'payer_name' => $buyer->name,
                'payer_email' => $buyer->email,
                'subtotal_amount' => 1000,
                'discount_amount' => 0,
                'net_amount' => 1000,
                'paid_amount' => 0,
                'currency' => 'BDT',
                'status' => 'unpaid',
            ]);
            $orderId = DB::table('commerce_orders')->insertGetId([
                'order_number' => 'MIXED-GIFT-ORDER-' . strtoupper(uniqid()),
                'user_id' => $buyer->id,
                'invoice_id' => $invoice->id,
                'branch_id' => null,
                'currency' => 'BDT',
                'subtotal' => 1000,
                'discount_total' => 0,
                'grand_total' => 1000,
                'status' => 'awaiting_payment',
                'payment_status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['course', $giftType] as $position => $itemType) {
                DB::table('commerce_order_items')->insert([
                    'order_id' => $orderId,
                    'item_type' => $itemType,
                    'item_id' => 998000 + $buyer->id + $position,
                    'title_snapshot' => $itemType === 'course' ? 'Ordinary course' : 'Recipient gift item',
                    'quantity' => 1,
                    'unit_price' => 500,
                    'line_total' => 500,
                    'metadata' => json_encode(['resource_id' => 998000 + $buyer->id + $position], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $transaction = PaymentTransaction::query()->create([
                'transaction_ref' => 'MIXED-GIFT-TXN-' . strtoupper(uniqid()),
                'invoice_id' => $invoice->id,
                'gateway_slug' => 'offline',
                'amount' => 1000,
                'currency' => 'BDT',
                'status' => 'initiated',
                'metadata' => [],
            ]);

            try {
                app(PaymentSettlementService::class)->settleTransaction(
                    $transaction,
                    'MIXED-GIFT-PROVIDER-' . $giftType,
                );
                $this->fail('A mixed buyer/gift order must not partially settle through generic buyer fulfillment.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Gift and voucher items require dedicated recipient fulfillment and cannot use buyer entitlements.',
                    $exception->getMessage(),
                );
            }

            $this->assertDatabaseHas('payment_transactions', [
                'id' => $transaction->id,
                'status' => 'initiated',
                'external_reference' => null,
            ]);
            $this->assertDatabaseHas('fee_invoices', [
                'id' => $invoice->id,
                'paid_amount' => 0,
                'status' => 'unpaid',
            ]);
            $this->assertDatabaseHas('commerce_orders', [
                'id' => $orderId,
                'status' => 'awaiting_payment',
                'payment_status' => 'unpaid',
            ]);
        }

        $this->assertDatabaseCount('commerce_entitlements', 0);
    }

    public function test_unified_commerce_fulfills_course_gifts_and_vouchers_without_buyer_entitlements(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart', 'course-management']);

        foreach (['gift_course', 'gift_voucher'] as $giftType) {
            $buyer = $this->createUserWithRole('student');
            $recipient = $this->createUserWithRole('student');
            $recipientEmail = $giftType . '.recipient@example.test';
            DB::table('users')->where('id', $recipient->id)->update(['email' => $recipientEmail]);
            $recipient->refresh();
            $token = bin2hex(random_bytes(32));
            $draftId = null;
            $courseId = null;

            if ($giftType === 'gift_course') {
                $course = Course::query()->create([
                    'code' => 'GIFT-SETTLE-' . strtoupper(bin2hex(random_bytes(3))),
                    'title' => 'Unified Gift Course',
                    'slug' => 'unified-gift-' . strtolower(bin2hex(random_bytes(3))),
                    'price' => 500,
                    'status' => 'published',
                    'branch_id' => null,
                ]);
                $courseId = (int) $course->id;
                $draftId = DB::table('course_gifts')->insertGetId([
                    'sender_user_id' => $buyer->id,
                    'recipient_user_id' => $recipient->id,
                    'recipient_email' => $recipientEmail,
                    'course_id' => $courseId,
                    'invoice_id' => null,
                    'redeem_token_hash' => hash('sha256', $token),
                    'redeem_token_encrypted' => encrypt($token),
                    'status' => 'awaiting_payment',
                    'currency' => 'BDT',
                    'amount' => 500,
                    'subtotal_amount' => 500,
                    'discount_amount' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $draftId = DB::table('course_gift_vouchers')->insertGetId([
                    'sender_user_id' => $buyer->id,
                    'recipient_user_id' => $recipient->id,
                    'recipient_email' => $recipientEmail,
                    'redeem_token_hash' => hash('sha256', $token),
                    'redeem_token_encrypted' => encrypt($token),
                    'currency' => 'BDT',
                    'amount' => 500,
                    'remaining_amount' => 500,
                    'status' => 'awaiting_payment',
                    'expires_at' => now()->addDays(30),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'UNIFIED-GIFT-' . strtoupper(bin2hex(random_bytes(4))),
                'payer_name' => $buyer->name,
                'payer_email' => $buyer->email,
                'subtotal_amount' => 500,
                'discount_amount' => 0,
                'net_amount' => 500,
                'paid_amount' => 0,
                'currency' => 'BDT',
                'status' => 'unpaid',
            ]);
            $orderId = DB::table('commerce_orders')->insertGetId([
                'order_number' => 'UNIFIED-GIFT-ORDER-' . strtoupper(bin2hex(random_bytes(4))),
                'user_id' => $buyer->id,
                'invoice_id' => $invoice->id,
                'branch_id' => null,
                'currency' => 'BDT',
                'subtotal' => 500,
                'discount_total' => 0,
                'grand_total' => 500,
                'status' => 'awaiting_payment',
                'payment_status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $orderItemId = DB::table('commerce_order_items')->insertGetId([
                'order_id' => $orderId,
                'item_type' => $giftType,
                'item_id' => $draftId,
                'title_snapshot' => $giftType === 'gift_course' ? 'Gift: Unified Gift Course' : 'Gift Voucher',
                'quantity' => 1,
                'unit_price' => 500,
                'line_total' => 500,
                'metadata' => json_encode($giftType === 'gift_course'
                    ? ['course_gift_id' => $draftId, 'course_id' => $courseId, 'recipient_email' => $recipientEmail]
                    : ['course_gift_voucher_id' => $draftId, 'recipient_email' => $recipientEmail], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $draftTable = $giftType === 'gift_course' ? 'course_gifts' : 'course_gift_vouchers';
            DB::table($draftTable)->where('id', $draftId)->update([
                'commerce_order_item_id' => $orderItemId,
                'updated_at' => now(),
            ]);

            $transaction = PaymentTransaction::query()->create([
                'transaction_ref' => 'UNIFIED-GIFT-TXN-' . strtoupper(bin2hex(random_bytes(4))),
                'invoice_id' => $invoice->id,
                'gateway_slug' => 'offline',
                'amount' => 500,
                'currency' => 'BDT',
                'status' => 'initiated',
                'metadata' => [],
            ]);

            app(PaymentSettlementService::class)->settleTransaction(
                $transaction,
                'UNIFIED-GIFT-PROVIDER-' . strtoupper($giftType),
            );

            $this->assertDatabaseHas($draftTable, [
                'id' => $draftId,
                'commerce_order_item_id' => $orderItemId,
                'invoice_id' => $invoice->id,
                'unified_payment_transaction_id' => $transaction->id,
                'status' => 'paid',
            ]);
            $this->assertDatabaseHas('payment_transactions', [
                'id' => $transaction->id,
                'status' => 'settled',
            ]);
            $this->assertDatabaseHas('fee_invoices', [
                'id' => $invoice->id,
                'status' => 'paid',
                'paid_amount' => 500,
            ]);
            $this->assertDatabaseHas('commerce_orders', [
                'id' => $orderId,
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);
            $this->assertDatabaseMissing('commerce_entitlements', [
                'user_id' => $buyer->id,
                'order_item_id' => $orderItemId,
            ]);

            if ($giftType === 'gift_course') {
                $this->actingAs($recipient)->post('/student/course-commerce/gifts/redeem/' . $token)
                    ->assertRedirect();
                $this->assertDatabaseHas('enrollments', [
                    'user_id' => $recipient->id,
                    'course_id' => $courseId,
                    'invoice_id' => $invoice->id,
                    'status' => 'active',
                ]);
            } else {
                $this->assertDatabaseCount('course_gift_wallets', 0);
                $this->assertDatabaseCount('course_gift_wallet_transactions', 0);
                $this->actingAs($recipient)->post('/student/course-commerce/vouchers/redeem/' . $token)
                    ->assertRedirect();
                $this->assertDatabaseHas('course_gift_vouchers', [
                    'id' => $draftId,
                    'recipient_user_id' => $recipient->id,
                    'remaining_amount' => 0,
                    'status' => 'redeemed',
                ]);
                $this->assertDatabaseHas('course_gift_wallet_transactions', [
                    'user_id' => $recipient->id,
                    'voucher_id' => $draftId,
                    'type' => 'credit',
                    'amount' => 500,
                    'status' => 'completed',
                ]);
                $this->assertSame(500.0, (float) DB::table('course_gift_wallets')->where('user_id', $recipient->id)->value('balance'));
            }
        }
    }

    public function test_late_verified_callback_cannot_resettle_a_refunded_transaction(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REFUND-GUARD-001',
            'payer_name' => 'Refund Guard Student',
            'payer_email' => 'refund.guard@aec.test',
            'subtotal_amount' => 1500,
            'discount_amount' => 0,
            'net_amount' => 1500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'REFUND-GUARD-TXN-001',
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 1500,
            'currency' => 'BDT',
            'status' => 'refunded',
            'metadata' => ['refund_reason' => 'full_refund'],
        ]);

        try {
            app(PaymentSettlementService::class)->settleTransaction(
                $transaction,
                'LATE-PROVIDER-CALLBACK-001',
                ['provider_status' => 'SUCCESS'],
            );
            $this->fail('A refunded transaction must never be settled again.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A refunded transaction cannot be settled again.', $exception->getMessage());
        }

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => 'refunded',
            'external_reference' => null,
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
    }
    public function test_refund_is_blocked_for_unified_gift_orders_until_recipient_reconciliation_exists(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart', 'course-management']);
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');

        $course = Course::query()->create([
            'code' => 'GIFT-REFUND-' . strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Refund Protected Gift Course',
            'slug' => 'refund-protected-gift-' . strtolower(bin2hex(random_bytes(3))),
            'price' => 500,
            'status' => 'published',
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'GIFT-REFUND-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 500,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'GIFT-REFUND-ORDER-' . strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 500,
            'discount_total' => 0,
            'grand_total' => 500,
            'status' => 'completed',
            'payment_status' => 'paid',
            'billing_snapshot' => json_encode(['name' => $buyer->name, 'email' => $buyer->email]),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $buyer->id,
            'recipient_user_id' => null,
            'recipient_email' => 'refund-recipient@example.test',
            'course_id' => $course->id,
            'invoice_id' => $invoice->id,
            'redeem_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'status' => 'paid',
            'currency' => 'BDT',
            'amount' => 500,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'gift_course',
            'item_id' => $giftId,
            'title_snapshot' => 'Gift: Refund Protected Gift Course',
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'metadata' => json_encode(['course_gift_id' => $giftId, 'course_id' => $course->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_gifts')->where('id', $giftId)->update(['commerce_order_item_id' => $orderItemId]);

        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'GIFT-REFUND-TXN-' . strtoupper(bin2hex(random_bytes(3))),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 500,
            'currency' => 'BDT',
            'status' => 'settled',
            'metadata' => [],
        ]);

        $this->actingAs($admin)->post('/admin/payments/' . $transaction->id . '/refund', [
            'amount' => 500,
            'reason' => 'Gift refund safety check',
        ])->assertSessionHasErrors('refund');

        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'status' => 'settled']);
        $this->assertDatabaseHas('fee_invoices', ['id' => $invoice->id, 'status' => 'paid', 'paid_amount' => 500]);
        $this->assertDatabaseHas('commerce_orders', ['id' => $orderId, 'status' => 'completed', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'paid', 'invoice_id' => $invoice->id]);
    }

    public function test_rejected_manual_gift_order_releases_its_draft_for_retry(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart', 'course-management', 'universal-payment']);
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $course = Course::query()->create([
            'code' => 'GIFT-REJECT-' . strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Retryable Gift Course',
            'slug' => 'retryable-gift-' . strtolower(bin2hex(random_bytes(3))),
            'price' => 500,
            'status' => 'published',
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'GIFT-REJECT-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'GIFT-REJECT-ORDER-' . strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 500,
            'discount_total' => 0,
            'grand_total' => 500,
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $buyer->id,
            'recipient_user_id' => null,
            'recipient_email' => 'retry-recipient@example.test',
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'status' => 'awaiting_payment',
            'currency' => 'BDT',
            'amount' => 500,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'gift_course',
            'item_id' => $giftId,
            'title_snapshot' => 'Gift: Retryable Gift Course',
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'metadata' => json_encode(['course_gift_id' => $giftId, 'course_id' => $course->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_gifts')->where('id', $giftId)->update(['commerce_order_item_id' => $itemId]);

        $manual = DB::table('universal_payment_transactions')->insertGetId([
            'reference' => 'UPAY-REJECT-' . strtoupper(bin2hex(random_bytes(3))),
            'payer_name' => $buyer->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Commerce Order',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 500,
            'external_trx_id' => 'BANK-REJECT-' . strtoupper(bin2hex(random_bytes(3))),
            'status' => 'pending',
            'user_id' => $buyer->id,
            'branch_id' => null,
            'fee_invoice_id' => $invoice->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/plugins/universal-payment/transactions/' . $manual . '/status', [
            'status' => 'rejected',
            'payment_reference' => 'BANK-REJECTED-RECEIPT',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('universal_payment_transactions', ['id' => $manual, 'status' => 'rejected']);
        $this->assertDatabaseHas('commerce_orders', ['id' => $orderId, 'status' => 'failed', 'payment_status' => 'failed']);
        $this->assertDatabaseHas('course_gifts', [
            'id' => $giftId,
            'status' => 'awaiting_payment',
            'invoice_id' => null,
            'commerce_order_item_id' => null,
        ]);
        $this->assertDatabaseHas('fee_invoices', ['id' => $invoice->id, 'status' => 'unpaid', 'paid_amount' => 0]);
    }

}
