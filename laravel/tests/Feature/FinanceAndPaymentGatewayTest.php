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
use Plugins\Modules\UniversalPayment\Services\UniversalPaymentOrchestrator;
use Tests\TestCase;

class FinanceAndPaymentGatewayTest extends TestCase
{
    public function test_card_gateway_refuses_checkout_without_acquirer_adapter_and_sslcommerz_works(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'fees-management',
            'payment-management',
            'gateway-card',
            'gateway-sslcommerz',
            'universal-payment',
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

        // Universal checkout must bind the payment to a real invoice and its owner.
        $this->actingAs($studentUser)->postJson('/universal-checkout', [
            'invoice_id' => $otherInvoice->id,
            'gateway' => 'sslcommerz',
        ])->assertForbidden();

        // Manual receipt registration requires an external bank/cash receipt reference.
        $this->actingAs($admin)->postJson('/plugins/universal-payment/transactions', [
            'payer_name' => 'Mobile Learner',
            'payer_contact' => '01712345678',
            'item_title' => 'Fee invoice INV-2026-001',
            'gateway' => 'bank_cash',
            'amount' => 2500,
        ])->assertUnprocessable()->assertJsonValidationErrors('external_trx_id');

        // The Universal Payment ledger accepts only offline records; they remain pending until reviewed.
        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Mobile Learner',
            'payer_contact' => '01712345678',
            'item_title' => 'Fee invoice INV-2026-001',
            'gateway' => 'bank_cash',
            'amount' => 2500,
            'external_trx_id' => 'BANK-RECEIPT-INV-001',
        ])->assertSessionHasNoErrors();
        $universalTx = DB::table('universal_payment_transactions')->first();
        $this->assertSame('pending', $universalTx->status);
        $this->actingAs($admin)->post("/plugins/universal-payment/transactions/{$universalTx->id}/status", [
            'status' => 'verified',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $universalTx->id,
            'status' => 'verified',
            'external_trx_id' => 'BANK-RECEIPT-INV-001',
        ]);

        $this->actingAs($admin)->post("/plugins/universal-payment/transactions/{$universalTx->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(422);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $universalTx->id,
            'status' => 'verified',
        ]);

        $cardPermissionId = DB::table('permissions')->where('slug', 'gateway.card.use')->value('id');
        $studentUser->roles()->first()->permissions()->syncWithoutDetaching([$cardPermissionId]);

        $this->actingAs($studentUser)->postJson('/payments/card/checkout', [
            'invoice_id' => $otherInvoice->id,
            'payment_token' => 'processor_token_123456789',
            'card_brand' => 'visa',
            'last4' => '4242',
        ])->assertForbidden();

        // Offline receipts must not create a dangling initiated transaction when the amount exceeds the invoice.
        $this->actingAs($admin)->post('/admin/payments/offline', [
            'invoice_id' => $invoice->id,
            'amount' => 2501,
            'receipt_note' => 'Overpayment test',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('payment_transactions', [
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
        ]);

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

        $this->actingAs($admin)->post('/admin/plugins/gateway-sslcommerz/test-connection')
            ->assertSessionHas('status');

        $universalHostedCheckout = $this->actingAs($studentUser)->post('/universal-checkout', [
            'invoice_id' => $sslInvoice->id,
            'gateway' => 'sslcommerz',
        ])->assertRedirect();
        $this->assertStringContainsString('sandbox.sslcommerz.com', $universalHostedCheckout->headers->get('Location'));

        $sslSession = $this->actingAs($studentUser)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $sslInvoice->id,
        ])->assertOk()->json();

        $sslTransaction = PaymentTransaction::query()
            ->where('transaction_ref', $sslSession['transaction_ref'])
            ->firstOrFail();

        // Public browser failure/cancel return URLs are forgeable and must not mutate payment state.
        $this->postJson('/payments/sslcommerz/failed', [
            'tran_id' => $sslTransaction->transaction_ref,
        ])->assertOk();
        $this->assertSame('initiated', $sslTransaction->fresh()->status);

        $this->postJson('/payments/sslcommerz/ipn', [
            'tran_id' => $sslTransaction->transaction_ref,
            'val_id' => 'SSL-VALIDATION-001',
            'status' => 'VALID',
        ])->assertOk()->assertJson(['status' => 'settled']);

        $this->assertSame('paid', $sslInvoice->fresh()->status);
        $this->assertSame('settled', $sslTransaction->fresh()->status);

        // Visa/Mastercard is a card network; never fake authorization without an acquiring PSP adapter.
        $cardResponse = $this->actingAs($admin)->postJson('/payments/card/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'tok_visa_1234567890',
            'card_brand' => 'visa',
            'last4' => '4242',
        ])->assertStatus(503);
        $this->assertStringContainsString('acquiring-bank/PSP adapter', $cardResponse->json('message'));

        $this->assertSame(0, PaymentTransaction::query()->where('gateway_slug', 'gateway-card')->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_students_can_start_own_stripe_and_paypal_checkout_but_cannot_capture_another_users_order(): void
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
            'secret_key' => 'sk_test_checkout_owner_123',
            'webhook_secret' => 'whsec_checkout_owner_123',
        ], ['secret_key', 'webhook_secret']);
        $plugins->saveSettings('gateway-paypal', [
            'client_id' => 'paypal_owner_client',
            'client_secret' => 'paypal_owner_secret',
            'webhook_id' => 'WH-OWNER-TEST',
        ], ['client_secret', 'webhook_id']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'PAY-OWNER',
            'name' => 'Payment Owner Branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'PAY-OWNER-01',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-PAY-OWNER-01',
            'student_id' => $student->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 1800,
            'discount_amount' => 0,
            'net_amount' => 1800,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        $outsider = $this->createUserWithRole('student', $branchId, 'payment-outsider@aec.test');

        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/v1/payment_intents')) {
                return Http::response([
                    'id' => 'pi_owner_checkout_001',
                    'status' => 'requires_action',
                    'client_secret' => 'pi_owner_checkout_001_secret',
                ], 200);
            }
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-owner-access-token', 'token_type' => 'Bearer'], 200);
            }
            if (str_contains($request->url(), '/v2/checkout/orders')) {
                return Http::response([
                    'id' => 'ORDER-OWNER-CHECKOUT-001',
                    'status' => 'CREATED',
                    'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-OWNER-CHECKOUT-001']],
                ], 201);
            }
            return Http::response(['message' => 'Unexpected payment request'], 404);
        });

        $this->actingAs($studentUser)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'pm_owner_checkout_token',
        ])->assertOk()->assertJsonPath('gateway', 'gateway-stripe');

        $this->actingAs($studentUser)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $invoice->id,
        ])->assertOk()->assertJsonPath('paypal_order_id', 'ORDER-OWNER-CHECKOUT-001');

        $this->actingAs($outsider)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'pm_other_user_token',
        ])->assertForbidden();

        $this->actingAs($outsider)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $invoice->id,
        ])->assertForbidden();

        $this->actingAs($outsider)->getJson('/payments/paypal/return?token=ORDER-OWNER-CHECKOUT-001')
            ->assertForbidden();

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
            'gateway-card',
            'universal-payment',
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
            if (str_contains($url, '/v1/account')) {
                return Http::response(['id' => 'acct_test_123', 'country' => 'US'], 200);
            }
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

        $this->actingAs($admin)->post('/admin/plugins/gateway-stripe/test-connection')
            ->assertSessionHas('status');
        $this->actingAs($admin)->post('/admin/plugins/gateway-paypal/test-connection')
            ->assertSessionHas('status');

        $gatewayStatuses = app(UniversalPaymentOrchestrator::class)->gatewayStatus();
        $this->assertTrue($gatewayStatuses['gateway-stripe']['available']);
        $this->assertTrue($gatewayStatuses['gateway-paypal']['available']);
        $this->assertSame('processor_adapter_required', $gatewayStatuses['gateway-card']['status']);

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

        // Caller-supplied references are not proof of a provider refund.
        // Online refunds must fail closed until a verified provider refund adapter exists.
        $tx = PaymentTransaction::query()->where('transaction_ref', $stripeRes1['transaction_ref'])->firstOrFail();

        $this->actingAs($admin)->post("/admin/payments/{$tx->id}/refund", [
            'amount' => 1500,
            'reason' => 'Unverified online refund attempt',
            'provider_refund_reference' => 're_forged_reference_001',
        ])->assertSessionHasErrors('refund');

        $this->assertDatabaseMissing('payment_refunds', [
            'payment_transaction_id' => $tx->id,
            'provider_refund_reference' => 're_forged_reference_001',
        ]);
        $this->assertSame('settled', $tx->fresh()->status);
        $this->assertSame('paid', $stripeInvoice->fresh()->status);
        $this->assertEquals(4500.0, (float) $stripeInvoice->fresh()->paid_amount);
    }

    public function test_standalone_invoice_payer_can_use_independent_gateways_without_student_plugin(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'fees-management',
            'payment-management',
            'gateway-sslcommerz',
            'gateway-stripe',
            'gateway-paypal',
            'universal-payment',
        ]);

        $plugins = app(PluginManager::class);
        $this->assertFalse($plugins->isInstalled('student-management'));
        $plugins->saveSettings('gateway-sslcommerz', [
            'store_id' => 'aec_standalone_store',
            'store_password' => 'ssl_standalone_secret',
            'sandbox_mode' => '1',
        ], ['store_password']);
        $plugins->saveSettings('gateway-stripe', [
            'publishable_key' => 'pk_test_standalone',
            'secret_key' => 'sk_test_standalone_123',
            'webhook_secret' => 'whsec_standalone_123',
        ], ['secret_key', 'webhook_secret']);
        $plugins->saveSettings('gateway-paypal', [
            'client_id' => 'paypal_standalone_client',
            'client_secret' => 'paypal_standalone_secret',
            'webhook_id' => 'WH-STANDALONE',
            'sandbox_mode' => '1',
        ], ['client_secret', 'webhook_id']);

        $payer = $this->createUserWithRole('student', null, 'standalone-payer@aec.test');
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-STANDALONE-PAY-01',
            'student_id' => null,
            'payer_name' => 'Standalone Payer',
            'payer_email' => 'standalone-payer@aec.test',
            'payer_phone' => '01712345678',
            'branch_id' => null,
            'subtotal_amount' => 3200,
            'discount_amount' => 0,
            'net_amount' => 3200,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/v1/payment_intents')) {
                return Http::response([
                    'id' => 'pi_standalone_001',
                    'status' => 'requires_action',
                    'client_secret' => 'pi_standalone_001_secret',
                ], 200);
            }
            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-standalone-token', 'token_type' => 'Bearer'], 200);
            }
            if (str_contains($url, '/v2/checkout/orders')) {
                return Http::response([
                    'id' => 'ORDER-STANDALONE-001',
                    'status' => 'CREATED',
                    'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-STANDALONE-001']],
                ], 201);
            }
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'sessionkey' => 'SSL-STANDALONE-SESSION',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?SESSIONKEY=SSL-STANDALONE-SESSION',
                ], 200);
            }
            return Http::response(['message' => 'Unexpected payment request'], 404);
        });

        $stripeCheckout = $this->actingAs($payer)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'pm_standalone_token',
        ])->assertOk()->assertJsonPath('gateway', 'gateway-stripe')->json();

        $this->actingAs($payer)->getJson('/payments/stripe/return?payment_intent=pi_standalone_001')
            ->assertOk()
            ->assertJsonPath('transaction_ref', $stripeCheckout['transaction_ref'])
            ->assertJsonPath('status', 'initiated');

        $this->actingAs($payer)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $invoice->id,
        ])->assertOk()->assertJsonPath('paypal_order_id', 'ORDER-STANDALONE-001');

        $this->actingAs($payer)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $invoice->id,
        ])->assertOk()->assertJsonPath('gateway', 'gateway-sslcommerz');

        $plugins->saveSettings('universal-payment', [
            'allow_offline_payments' => '0',
        ]);
        $financeAdmin = $this->createUserWithRole('admin');
        $this->actingAs($financeAdmin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Standalone Payer',
            'item_title' => 'Invoice INV-STANDALONE-PAY-01',
            'gateway' => 'bank_cash',
            'amount' => 3200,
            'external_trx_id' => 'BANK-REF-STANDALONE-01',
        ])->assertForbidden();
    }


    public function test_paypal_gateway_fails_closed_when_fee_and_payment_integrations_are_disabled(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-paypal', 'gateway-stripe', 'gateway-sslcommerz']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->postJson('/payments/paypal/create-order', [
            'invoice_id' => 1,
        ])->assertStatus(409)->assertJsonPath('message', 'Enable Fees and Payment Management before using this gateway.');

        $this->postJson('/payments/paypal/webhook', [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [],
        ])->assertStatus(409)->assertJsonPath('message', 'Enable Fees and Payment Management before using this gateway.');

        $this->actingAs($student)->postJson('/payments/stripe/checkout', [
            'invoice_id' => 1,
            'payment_token' => 'pm_missing_dependencies',
        ])->assertStatus(409)->assertJsonPath('message', 'Enable Fees and Payment Management before using this gateway.');

        $this->postJson('/payments/stripe/webhook', [])->assertStatus(409);
        $this->actingAs($student)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => 1,
        ])->assertStatus(409)->assertJsonPath('message', 'Enable Fees and Payment Management before using this gateway.');
    }

}
