<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request as ClientRequest;
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

        $otherStudentUser = $this->createUserWithRole('student', $branchId, 'other-student@aec.test');
        $otherStudent = Student::query()->create([
            'user_id' => $otherStudentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'STU-102',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $otherInvoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-SSL-OTHER',
            'student_id' => $otherStudent->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 800,
            'discount_amount' => 0,
            'net_amount' => 800,
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

        // A student may pay their own invoice, but cannot initiate checkout for another student's invoice.
        $this->actingAs($studentUser)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $otherInvoice->id,
        ])->assertForbidden();

        // SSLCOMMERZ must create a server-side session and validate the IPN with the official validation API.
        $sslTransactionRef = '';
        Http::fake(function (ClientRequest $request) use (&$sslTransactionRef) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/gwprocess/v4/api.php')) {
                $sslTransactionRef = (string) ($request->data()['tran_id'] ?? '');

                return Http::response([
                    'status' => 'SUCCESS',
                    'sessionkey' => 'SSL-SESSION-KEY-001',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?SESSIONKEY=SSL-SESSION-KEY-001',
                ], 200);
            }

            return Http::response([
                'status' => 'VALID',
                'tran_id' => $sslTransactionRef,
                'val_id' => 'SSL-VALIDATION-001',
                'amount' => '1200.00',
                'currency' => 'BDT',
                'bank_tran_id' => 'BANK-SSL-001',
                'risk_level' => '0',
            ], 200);
        });

        $sslSession = $this->actingAs($studentUser)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $sslInvoice->id,
        ])->assertOk()->json();

        $sslTransaction = PaymentTransaction::query()
            ->where('transaction_ref', $sslSession['transaction_ref'])
            ->firstOrFail();

        $this->postJson('/payments/sslcommerz/ipn', [
            'tran_id' => $sslTransaction->transaction_ref,
            'val_id' => 'SSL-VALIDATION-001',
            'status' => 'VALID',
        ])->assertOk()->assertJson(['status' => 'settled']);

        $this->assertSame('paid', $sslInvoice->fresh()->status);
        $this->assertSame('settled', $sslTransaction->fresh()->status);

        // Visa/Mastercard is a card network; never fake authorization without an acquiring PSP adapter.
        $this->actingAs($admin)->postJson('/payments/card/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'tok_visa_1234567890',
            'card_brand' => 'visa',
            'last4' => '4242',
        ])->assertStatus(503)->assertJsonPath('message', fn ($message) => str_contains($message, 'acquiring-bank/PSP adapter'));

        $this->assertSame(0, PaymentTransaction::query()->where('gateway_slug', 'gateway-card')->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
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

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/v1/payment_intents')) {
                return Http::response([
                    'id' => 'pi_test_settled_1',
                    'status' => 'requires_action',
                    'client_secret' => 'pi_test_settled_1_secret_test',
                ], 200);
            }
            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-test-access-token', 'token_type' => 'Bearer', 'expires_in' => 300], 200);
            }
            if (str_contains($url, '/v2/checkout/orders')) {
                return Http::response([
                    'id' => 'ORDER-TEST-001',
                    'status' => 'CREATED',
                    'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-TEST-001']],
                ], 201);
            }
            if (str_contains($url, '/v1/notifications/verify-webhook-signature')) {
                return Http::response(['verification_status' => 'SUCCESS'], 200);
            }
            return Http::response(['message' => 'Unexpected fake payment endpoint'], 404);
        });

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
            'id' => 'evt_test_payment_intent_succeeded',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_test_settled_1',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 450000,
                'amount_received' => 450000,
                'currency' => 'bdt',
                'metadata' => ['transaction_ref' => $stripeRes1['transaction_ref']],
            ]],
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
            'id' => 'WH-TEST-CAPTURE-001',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-998811',
                'status' => 'COMPLETED',
                'custom_id' => $paypalRes['transaction_ref'],
                'amount' => ['currency_code' => 'BDT', 'value' => '2500.00'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/payments/paypal/webhook',
            [],
            [],
            [],
            [
                'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
                'HTTP_PAYPAL_CERT_URL' => 'https://api-m.sandbox.paypal.com/cert',
                'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-test-001',
                'HTTP_PAYPAL_TRANSMISSION_SIG' => 'signature-test',
                'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
                'CONTENT_TYPE' => 'application/json',
            ],
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
