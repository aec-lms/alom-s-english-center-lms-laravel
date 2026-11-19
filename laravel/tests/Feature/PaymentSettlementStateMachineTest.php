<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
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

        foreach (['currency', 'total', 'item_math'] as $case) {
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
                'item_type' => 'course',
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
                    default => 'A commerce order item quantity, unit price, and line total do not reconcile.',
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
}
