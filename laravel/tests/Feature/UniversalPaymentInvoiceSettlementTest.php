<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
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

    public function test_configured_bkash_and_nagad_manual_invoice_receipts_can_be_verified(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment']);
        $studentUser = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');

        foreach (['bkash', 'nagad'] as $gateway) {
            $invoice = FeeInvoice::query()->create([
                'invoice_number' => 'UPAY-' . strtoupper($gateway) . '-' . $studentUser->id,
                'payer_name' => $studentUser->name,
                'payer_email' => $studentUser->email,
                'payer_phone' => '01712345678',
                'subtotal_amount' => 750,
                'discount_amount' => 0,
                'net_amount' => 750,
                'paid_amount' => 0,
                'currency' => 'BDT',
                'status' => 'unpaid',
            ]);
            $externalReference = strtoupper($gateway) . '-MANUAL-' . $studentUser->id;

            $this->actingAs($studentUser)->post('/universal-checkout', [
                'payer_name' => $studentUser->name,
                'payer_contact' => '01712345678',
                'item_title' => 'Manual ' . strtoupper($gateway) . ' invoice',
                'gateway' => $gateway,
                'external_trx_id' => $externalReference,
                'fee_invoice_id' => $invoice->id,
            ])->assertRedirect()->assertSessionHasNoErrors();

            $transaction = UniversalPaymentTransaction::query()
                ->where('fee_invoice_id', $invoice->id)
                ->firstOrFail();
            $this->assertSame($gateway, $transaction->gateway);
            $this->actingAs($admin)->get('/plugins/universal-payment')->assertOk()
                ->assertSee('name="payment_reference"', false)
                ->assertSee($externalReference);

            $this->actingAs($admin)->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'verified',
                'payment_reference' => $externalReference,
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseHas('fee_invoices', [
                'id' => $invoice->id,
                'paid_amount' => 750,
                'status' => 'paid',
            ]);
            $this->assertDatabaseHas('universal_payment_transactions', [
                'id' => $transaction->id,
                'gateway' => $gateway,
                'status' => 'verified',
            ]);
        }
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

    public function test_manual_invoice_verification_rejects_student_branch_mismatch_before_granting_enrollment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'course-management', 'fees-management', 'universal-payment', 'branch-management']);

        $studentUser = $this->createUserWithRole('student');
        $studentBranchId = DB::table('branches')->insertGetId([
            'code' => 'UPAY-STUDENT-' . $studentUser->id,
            'name' => 'Student Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseBranchId = DB::table('branches')->insertGetId([
            'code' => 'UPAY-COURSE-' . $studentUser->id,
            'name' => 'Course Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $studentUser->id)->update(['branch_id' => $studentBranchId]);

        $courseId = DB::table('courses')->insertGetId([
            'code' => 'UPAY-BRANCH-' . $studentUser->id,
            'title' => 'Branch Protected Course',
            'slug' => 'upay-branch-protected-' . $studentUser->id,
            'price' => 1000,
            'status' => 'published',
            'branch_id' => $courseBranchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-BRANCH-INV-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_email' => $studentUser->email,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
            'branch_id' => $courseBranchId,
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $studentUser->id,
            'course_id' => $courseId,
            'branch_id' => $courseBranchId,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-BRANCH-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Branch protected invoice',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => 'BANK-UPAY-BRANCH-' . $studentUser->id,
            'user_id' => $studentUser->id,
            'branch_id' => $courseBranchId,
            'fee_invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'verified',
                'payment_reference' => 'BANK-UPAY-BRANCH-VERIFY-' . $studentUser->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $studentUser->id,
            'course_id' => $courseId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
        ]);
    }


    public function test_manual_invoice_payment_fulfills_commerce_order_without_referral_plugin(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment', 'commerce-cart', 'payment-management']);

        $student = $this->createUserWithRole('student');
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-COMMERCE-' . $student->id,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'subtotal_amount' => 650,
            'discount_amount' => 0,
            'net_amount' => 650,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'UPAY-COM-' . $student->id,
            'user_id' => $student->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 650,
            'discount_total' => 0,
            'grand_total' => 650,
            'status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'subscription',
            'item_id' => 44,
            'title_snapshot' => 'Manual subscription',
            'quantity' => 1,
            'unit_price' => 650,
            'line_total' => 650,
            'metadata' => json_encode(['entitlement' => ['duration_days' => 30]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-COMMERCE-TX-' . $student->id,
            'payer_name' => $student->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Commerce invoice',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 650,
            'external_trx_id' => 'BANK-UPAY-COMMERCE-' . $student->id,
            'user_id' => $student->id,
            'fee_invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'verified',
                'payment_reference' => 'BANK-UPAY-COMMERCE-VERIFIED-' . $student->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 650,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 650,
            'status' => 'settled',
        ]);
        $this->assertDatabaseHas('commerce_entitlements', [
            'user_id' => $student->id,
            'order_item_id' => $orderItemId,
            'entitlement_type' => 'subscription',
            'status' => 'active',
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
            ->assertStatus(422);

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

    public function test_manual_provider_reference_cannot_be_reused_across_different_invoices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'universal-payment']);

        $studentUser = $this->createUserWithRole('student');
        $firstInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-DUP-A-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_email' => $studentUser->email,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        $secondInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'UPAY-DUP-B-' . $studentUser->id,
            'payer_name' => $studentUser->name,
            'payer_email' => $studentUser->email,
            'subtotal_amount' => 700,
            'discount_amount' => 0,
            'net_amount' => 700,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'First invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-REPLAY-001',
            'fee_invoice_id' => $firstInvoice->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Provider transaction IDs are normalized before claiming, so casing
        // differences cannot be used to submit the same receipt twice.
        $this->actingAs($studentUser)->post('/universal-checkout', [
            'payer_name' => $studentUser->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Second invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'bank-replay-001',
            'fee_invoice_id' => $secondInvoice->id,
        ])->assertSessionHasErrors('external_trx_id');

        $this->assertDatabaseCount('universal_payment_transactions', 1);
        $this->assertDatabaseCount('universal_payment_reference_claims', 1);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $firstInvoice->id,
            'paid_amount' => 0,
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $secondInvoice->id,
            'paid_amount' => 0,
        ]);
    }


    public function test_unverified_sslcommerz_browser_failure_and_cancel_do_not_release_pending_checkout(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);

        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-UNVERIFIED-RETURN-001',
            'payer_name' => 'Callback Test Buyer',
            'payer_contact' => '01712345678',
            'item_title' => 'Paid e-book',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 500,
            'status' => 'pending',
        ]);

        $gateway = app(\\Plugins\\Modules\\UniversalPayment\\Services\\StorefrontPaymentGatewayService::class);

        $failedReturn = $gateway->markSslcommerzFailed(['tran_id' => $transaction->reference], 'failed');
        $this->assertSame('pending', $failedReturn->status);

        $cancelledReturn = $gateway->markSslcommerzFailed(['tran_id' => $transaction->reference], 'cancelled');
        $this->assertSame('pending', $cancelledReturn->status);

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
        ]);
    }

}
