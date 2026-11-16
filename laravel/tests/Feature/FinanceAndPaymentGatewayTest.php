<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class FinanceAndPaymentGatewayTest extends TestCase
{
    public function test_card_gateway_rejects_raw_pan_and_cvv_and_settles_via_signed_webhook(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'fees-management',
            'payment-management',
            'gateway-card',
            'gateway-sslcommerz',
        ]);

        $plugins = app(PluginManager::class);
        $plugins->saveSettings('gateway-card', [
            'merchant_id' => 'MRC-100',
            'api_secret' => 'sec_live_test',
            'webhook_secret' => 'whsec_test_123456',
        ], ['merchant_id', 'api_secret', 'webhook_secret']);
        $plugins->saveSettings('gateway-sslcommerz', [
            'store_id' => 'aec_sandbox_store',
            'store_password' => 'ssl_sandbox_password',
            'sandbox_mode' => '1',
        ], ['store_password']);

        $branchId = DB::table('branches')->insertGetId(['code' => 'DHK', 'name' => 'Dhaka', 'created_at' => now(), 'updated_at' => now()]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        $studentUser->update(['phone' => '01712345678']);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'STU-101',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-2026-001',
            'student_id' => $student->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 3000,
            'discount_amount' => 500,
            'net_amount' => 2500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $sslInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-SSL-001',
            'student_id' => $student->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 1200,
            'discount_amount' => 0,
            'net_amount' => 1200,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $admin = $this->createUserWithRole('admin');

        // Raw PAN/CVV must be rejected with 422 on both gateways
        $this->actingAs($admin)->postJson('/payments/card/checkout', [
            'invoice_id' => $invoice->id,
            'card_number' => '4111111111111111',
            'cvv' => '123',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $invoice->id,
            'pan' => '4111111111111111',
        ])->assertStatus(422);

        // SSLCOMMERZ must create a server-side session and validate the IPN with the official validation API.
        Http::fake([
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'SUCCESS',
                'sessionkey' => 'SSL-SESSION-KEY-001',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?SESSIONKEY=SSL-SESSION-KEY-001',
            ], 200),
            'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'SSL-PLACEHOLDER',
                'val_id' => 'SSL-VALIDATION-001',
                'amount' => '1200.00',
                'currency' => 'BDT',
                'bank_tran_id' => 'BANK-SSL-001',
                'risk_level' => '0',
            ], 200),
        ]);

        $sslSession = $this->actingAs($admin)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $sslInvoice->id,
        ])->assertOk()->json();

        $sslTransaction = PaymentTransaction::query()
            ->where('transaction_ref', $sslSession['transaction_ref'])
            ->firstOrFail();

        Http::fake([
            'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $sslTransaction->transaction_ref,
                'val_id' => 'SSL-VALIDATION-001',
                'amount' => '1200.00',
                'currency' => 'BDT',
                'bank_tran_id' => 'BANK-SSL-001',
                'risk_level' => '0',
            ], 200),
        ]);

        $this->postJson('/payments/sslcommerz/ipn', [
            'tran_id' => $sslTransaction->transaction_ref,
            'val_id' => 'SSL-VALIDATION-001',
            'status' => 'VALID',
        ])->assertOk()->assertJson(['status' => 'settled']);

        $this->assertSame('paid', $sslInvoice->fresh()->status);
        $this->assertSame('settled', $sslTransaction->fresh()->status);

        // Tokenized card checkout succeeds and stores zero PAN/CVV
        $checkout = $this->actingAs($admin)->postJson('/payments/card/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'tok_visa_1234567890',
            'card_brand' => 'visa',
            'last4' => '4242',
        ])->assertOk()->json();

        $payload = json_encode([
            'transaction_ref' => $checkout['transaction_ref'],
            'processor_reference' => 'PROC-998877',
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $payload, 'whsec_test_123456');

        $this->call(
            'POST',
            '/payments/card/webhook',
            [],
            [],
            [],
            ['HTTP_X_CARD_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $tx = PaymentTransaction::query()->where('transaction_ref', $checkout['transaction_ref'])->firstOrFail();
        $this->assertSame('settled', $tx->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertArrayNotHasKey('card_number', $tx->metadata ?? []);
        $this->assertArrayNotHasKey('cvv', $tx->metadata ?? []);
    }

    public function test_stripe_and_paypal_gateways_and_atomic_refund_workflow(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'fees-management',
            'payment-management',
            'gateway-stripe',
            'gateway-paypal',
        ]);

        $plugins = app(PluginManager::class);
        $plugins->saveSettings('gateway-stripe', [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123456789',
            'webhook_secret' => 'whsec_stripe_abc',
            'sandbox_mode' => '1',
        ], ['secret_key', 'webhook_secret']);

        $plugins->saveSettings('gateway-paypal', [
            'client_id' => 'pp_client_123',
            'client_secret' => 'pp_secret_123',
            'webhook_id' => 'whsec_paypal_xyz',
            'sandbox_mode' => '1',
        ], ['client_secret', 'webhook_id']);

        $branchId = DB::table('branches')->insertGetId(['code' => 'CTG', 'name' => 'Chattogram', 'created_at' => now(), 'updated_at' => now()]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'STU-202',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $stripeInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-STRP-01',
            'student_id' => $student->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 4500,
            'discount_amount' => 0,
            'net_amount' => 4500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $paypalInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-PYPL-01',
            'student_id' => $student->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 2500,
            'discount_amount' => 0,
            'net_amount' => 2500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $admin = $this->createUserWithRole('admin');

        // Raw PAN rejection on Stripe & PayPal
        $this->actingAs($admin)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $stripeInvoice->id,
            'card_number' => '4111111111111111',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $paypalInvoice->id,
            'cvv' => '999',
        ])->assertStatus(422);

        // Stripe tokenized checkout + idempotency + signed webhook
        $stripeRes1 = $this->actingAs($admin)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $stripeInvoice->id,
            'payment_token' => 'pm_card_visa_token',
            'idempotency_key' => 'idem-stripe-1',
        ])->assertOk()->json();

        $stripeRes2 = $this->actingAs($admin)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $stripeInvoice->id,
            'payment_token' => 'pm_card_visa_token',
            'idempotency_key' => 'idem-stripe-1',
        ])->assertOk()->json();
        $this->assertSame($stripeRes1['transaction_ref'], $stripeRes2['transaction_ref']);

        $stripePayload = json_encode([
            'transaction_ref' => $stripeRes1['transaction_ref'],
            'payment_intent_id' => 'pi_test_settled_1',
        ], JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $stripeSig = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $stripePayload, 'whsec_stripe_abc');

        $this->call(
            'POST',
            '/payments/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $stripeSig, 'CONTENT_TYPE' => 'application/json'],
            $stripePayload
        )->assertOk();

        $this->assertSame('paid', $stripeInvoice->fresh()->status);

        // PayPal hosted order + signed webhook
        $paypalRes = $this->actingAs($admin)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $paypalInvoice->id,
            'idempotency_key' => 'idem-paypal-1',
        ])->assertOk()->json();

        $paypalPayload = json_encode([
            'transaction_ref' => $paypalRes['transaction_ref'],
            'capture_id' => 'CAP-998811',
        ], JSON_THROW_ON_ERROR);
        $paypalSig = hash_hmac('sha256', $paypalPayload, 'whsec_paypal_xyz');

        $this->call(
            'POST',
            '/payments/paypal/webhook',
            [],
            [],
            [],
            ['HTTP_X_PAYPAL_SIGNATURE' => $paypalSig, 'CONTENT_TYPE' => 'application/json'],
            $paypalPayload
        )->assertOk();

        $this->assertSame('paid', $paypalInvoice->fresh()->status);

        // Atomic refund & over-refund rejection
        $tx = PaymentTransaction::query()->where('transaction_ref', $stripeRes1['transaction_ref'])->firstOrFail();

        $this->actingAs($admin)->post("/admin/payments/{$tx->id}/refund", [
            'amount' => 9999,
            'reason' => 'Over-refund attempt',
        ])->assertSessionHasErrors('refund');

        $this->actingAs($admin)->post("/admin/payments/{$tx->id}/refund", [
            'amount' => 1500,
            'reason' => 'Partial fee adjustment',
        ])->assertSessionHasNoErrors();

        $this->assertSame('partial', $stripeInvoice->fresh()->status);
        $this->assertEquals(3000.0, (float) $stripeInvoice->fresh()->paid_amount);
    }
}
