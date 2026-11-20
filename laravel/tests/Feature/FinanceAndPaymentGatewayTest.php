<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;
use Plugins\Modules\GatewayStripe\Services\StripeGatewayService;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use RuntimeException;
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
                'amount' => (($request->data()['val_id'] ?? '') === 'SSL-VALIDATION-WRONG' ? '1199.00' : '1200.00'),
                'currency' => 'BDT',
                'bank_tran_id' => 'BANK-SSL-001',
                'risk_level' => '0',
            ], 200);
        });

        $this->actingAs($admin)->post('/admin/plugins/gateway-sslcommerz/test-connection')
            ->assertSessionHas('status');

        $sslSession = $this->actingAs($studentUser)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $sslInvoice->id,
            'idempotency_key' => 'idem-ssl-001',
        ])->assertOk()->json();
        $sslRequestCount = Http::recorded()->filter(
            fn (array $record): bool => str_ends_with((string) parse_url($record[0]->url(), PHP_URL_PATH), '/gwprocess/v4/api.php')
                && ($record[0]->data()['tran_id'] ?? '') === $sslSession['transaction_ref']
        )->count();
        $sslReplay = $this->actingAs($studentUser)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $sslInvoice->id,
            'idempotency_key' => 'idem-ssl-001',
        ])->assertOk()->json();
        $this->assertSame($sslSession['transaction_ref'], $sslReplay['transaction_ref']);
        $this->assertSame($sslSession['redirect_url'], $sslReplay['redirect_url']);
        $this->assertSame($sslRequestCount, Http::recorded()->filter(
            fn (array $record): bool => str_ends_with((string) parse_url($record[0]->url(), PHP_URL_PATH), '/gwprocess/v4/api.php')
                && ($record[0]->data()['tran_id'] ?? '') === $sslSession['transaction_ref']
        )->count());

        $sslTransaction = PaymentTransaction::query()
            ->where('transaction_ref', $sslSession['transaction_ref'])
            ->firstOrFail();

        try {
            app(SslcommerzGatewayService::class)->verifyAndSettleIpn([
                'tran_id' => $sslTransaction->transaction_ref,
                'val_id' => 'SSL-VALIDATION-WRONG',
                'status' => 'VALID',
            ]);
            $this->fail('SSLCOMMERZ must reject a callback with the wrong amount.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('amount', strtolower($exception->getMessage()));
        }
        $this->assertSame('unpaid', $sslInvoice->fresh()->status);

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

    public function test_refund_is_blocked_for_unified_gift_orders_before_financial_mutation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'GIFT-REFUND-GUARD-' . strtoupper(bin2hex(random_bytes(3))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'GIFT-REFUND-ORDER-' . strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 1000,
            'discount_total' => 0,
            'grand_total' => 1000,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commerce_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'gift_voucher',
            'item_id' => 987654,
            'title_snapshot' => 'Gift Voucher',
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'metadata' => json_encode(['course_gift_voucher_id' => 987654], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'GIFT-REFUND-TXN-' . strtoupper(bin2hex(random_bytes(3))),
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
            'metadata' => [],
            'settled_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/payments/' . $transaction->id . '/refund', [
            'amount' => 1000,
            'reason' => 'Gift refund safety regression',
        ])->assertSessionHasErrors('refund');

        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => 'settled',
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
            'paid_amount' => 1000,
        ]);
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
        $stripeIntentRequestCount = Http::recorded()->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/v1/payment_intents')
        )->count();

        $stripeRes2 = $this->actingAs($admin)->postJson('/payments/stripe/checkout', [
            'invoice_id' => $stripeInvoice->id,
            'payment_token' => 'pm_card_visa_token',
            'idempotency_key' => 'idem-stripe-1',
        ])->assertOk()->json();
        $this->assertSame($stripeRes1['transaction_ref'], $stripeRes2['transaction_ref']);
        $this->assertSame($stripeIntentRequestCount, Http::recorded()->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/v1/payment_intents')
        )->count());

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

        $stripeEvent = json_decode($stripePayload, true, 512, JSON_THROW_ON_ERROR);
        $stripeEvent['data']['object']['amount_received'] = 449999;
        $wrongAmountPayload = json_encode($stripeEvent, JSON_THROW_ON_ERROR);
        $wrongAmountSignature = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $wrongAmountPayload, 'whsec_stripe_abc');
        try {
            app(StripeGatewayService::class)->verifyAndSettleWebhook($wrongAmountPayload, $wrongAmountSignature);
            $this->fail('Stripe must reject a PaymentIntent with the wrong amount.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('amount', strtolower($exception->getMessage()));
        }
        try {
            app(StripeGatewayService::class)->verifyAndSettleWebhook($stripePayload, 't=' . $ts . ',v1=invalid-signature');
            $this->fail('Stripe must reject an invalid webhook signature.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('signature', strtolower($exception->getMessage()));
        }
        $this->assertSame('unpaid', $stripeInvoice->fresh()->status);

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
        $this->assertSame(
            'settled',
            app(StripeGatewayService::class)->verifyAndSettleWebhook($stripePayload, $stripeSig)->status
        );

        // PayPal hosted order + signed webhook
        $paypalRes = $this->actingAs($admin)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $paypalInvoice->id,
            'idempotency_key' => 'idem-paypal-1',
        ])->assertOk()->json();
        $paypalOrderRequestCount = Http::recorded()->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/v2/checkout/orders')
        )->count();
        $paypalReplay = $this->actingAs($admin)->postJson('/payments/paypal/create-order', [
            'invoice_id' => $paypalInvoice->id,
            'idempotency_key' => 'idem-paypal-1',
        ])->assertOk()->json();
        $this->assertSame($paypalRes['transaction_ref'], $paypalReplay['transaction_ref']);
        $this->assertSame($paypalRes['approval_url'], $paypalReplay['approval_url']);
        $this->assertSame($paypalOrderRequestCount, Http::recorded()->filter(
            fn (array $record): bool => str_contains($record[0]->url(), '/v2/checkout/orders')
        )->count());

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

        $paypalHeaders = [
            'auth_algo' => 'SHA256withRSA',
            'cert_url' => 'https://api-m.sandbox.paypal.com/cert',
            'transmission_id' => 'transmission-test-001',
            'transmission_sig' => 'signature-test',
            'transmission_time' => now()->toIso8601String(),
        ];
        $wrongPaypalEvent = json_decode($paypalPayload, true, 512, JSON_THROW_ON_ERROR);
        $wrongPaypalEvent['resource']['amount']['value'] = '2499.00';
        try {
            app(PaypalGatewayService::class)->verifyAndSettleWebhook($paypalHeaders, $wrongPaypalEvent);
            $this->fail('PayPal must reject a capture with the wrong amount.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('amount', strtolower($exception->getMessage()));
        }
        $this->assertSame('unpaid', $paypalInvoice->fresh()->status);

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
                'HTTP_PAYPAL_TRANSMISSION_TIME' => $paypalHeaders['transmission_time'],
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
            'provider_refund_reference' => 're_test_overrefund_001',
        ])->assertSessionHasErrors('refund');

        // Online refunds must remain fail-closed until a verified provider refund adapter exists.
        $this->actingAs($admin)->post("/admin/payments/{$tx->id}/refund", [
            'amount' => 1500,
            'reason' => 'Partial fee adjustment',
            'provider_refund_reference' => 're_test_provider_refund_001',
        ])->assertSessionHasErrors('refund');

        $this->assertDatabaseMissing('payment_refunds', [
            'payment_transaction_id' => $tx->id,
            'provider_refund_reference' => 're_test_provider_refund_001',
        ]);

        $this->assertSame('paid', $stripeInvoice->fresh()->status);
        $this->assertEquals(4500.0, (float) $stripeInvoice->fresh()->paid_amount);
    }
}
