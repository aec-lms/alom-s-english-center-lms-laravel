<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Plugins\\Modules\\FeesManagement\\Models\\FeeInvoice;
use Plugins\\Modules\\PaymentManagement\\Models\\PaymentTransaction;
use Plugins\\Modules\\PaymentManagement\\Services\\PaymentSettlementService;
use RuntimeException;
use Tests\\TestCase;

class PaymentSettlementStateMachineTest extends TestCase
{
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
