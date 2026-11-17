<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UniversalPaymentInvoiceSettlementTest extends TestCase
{
    public function test_manual_universal_payment_receipt_atomically_settles_its_fee_invoice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment']);

        $studentUser = $this->createUserWithRole('student');
        Student::query()->create([
            'user_id' => $studentUser->id,
            'student_code' => 'UPAY-' . $studentUser->id,
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-INV-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_email' => $studentUser->email,
            'payer_phone' => '01712345678',
            'subtotal_amount' => 1500,
            'discount_amount' => 300,
            'net_amount' => 1200,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        // The universal ledger must not accept an online gateway that has no invoice callback adapter.
        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition invoice',
            'gateway' => 'sslcommerz',
            'external_trx_id' => 'SSL-UNSUPPORTED-INVOICE',
            'fee_invoice_id' => $invoice->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('universal_payment_transactions', [
            'fee_invoice_id' => $invoice->id,
        ]);

        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-RECEIPT-UPAY-001',
            'fee_invoice_id' => $invoice->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transaction = UniversalPaymentTransaction::query()
            ->where('fee_invoice_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame('bank_cash', $transaction->gateway);
        $this->assertSame('1200.00', $transaction->amount);
        $this->assertSame('BANK-RECEIPT-UPAY-001', $transaction->external_trx_id);

        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-RECEIPT-UPAY-DUPLICATE',
            'fee_invoice_id' => $invoice->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('universal_payment_transactions', 1);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'verified',
                'payment_reference' => 'BANK-VERIFIED-UPAY-001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 1200,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'verified',
            'external_trx_id' => 'BANK-VERIFIED-UPAY-001',
            'verified_by' => $admin->id,
        ]);
    }

    public function test_rejected_universal_invoice_receipt_does_not_change_invoice_balance(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment']);

        $studentUser = $this->createUserWithRole('student');
        Student::query()->create([
            'user_id' => $studentUser->id,
            'student_code' => 'UPAY-REJ-' . $studentUser->id,
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-REJ-INV-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_email' => $studentUser->email,
            'subtotal_amount' => 900,
            'discount_amount' => 0,
            'net_amount' => 900,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-REJECT-UPAY-001',
            'fee_invoice_id' => $invoice->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transaction = UniversalPaymentTransaction::query()->where('fee_invoice_id', $invoice->id)->firstOrFail();
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)
            ->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'rejected',
                'payment_reference' => 'BANK-REJECTED-UPAY-001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'rejected',
        ]);
    }
    public function test_invoice_checkout_fails_closed_when_fee_plugin_storage_is_absent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);

        $studentUser = $this->createUserWithRole('student');
        $this->assertFalse(Schema::hasTable('fee_invoices'));

        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-NO-FEES-TABLE',
            'fee_invoice_id' => 999,
        ])->assertStatus(503);
    }

    public function test_checkout_rejects_ambiguous_invoice_and_ebook_targets(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment']);

        $studentUser = $this->createUserWithRole('student');
        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Ambiguous checkout',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-AMBIGUOUS-001',
            'fee_invoice_id' => 999,
            'storefront_item_id' => 999,
        ])->assertStatus(422);
    }

    public function test_overpayment_receipt_cannot_be_verified_against_remaining_invoice_balance(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment']);

        $studentUser = $this->createUserWithRole('student');
        Student::query()->create([
            'user_id' => $studentUser->id,
            'student_code' => 'UPAY-OVER-' . $studentUser->id,
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-OVER-INV-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_email' => $studentUser->email,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 800,
            'currency' => 'BDT',
            'status' => 'partial',
        ]);
        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-OVER-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Invoice ' . $invoice->invoice_number,
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 300,
            'external_trx_id' => 'BANK-OVERPAY-001',
            'user_id' => $studentUser->id,
            'fee_invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'verified',
                'payment_reference' => 'BANK-OVERPAY-VERIFY',
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 800,
            'status' => 'partial',
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
        ]);
    }

}
