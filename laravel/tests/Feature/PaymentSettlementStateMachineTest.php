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
