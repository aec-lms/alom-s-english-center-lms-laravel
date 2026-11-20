<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Services\InvoicePaymentSessionReservationService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvoicePaymentSessionReservationTest extends TestCase
{
    private function createInvoice(): FeeInvoice
    {
        return FeeInvoice::query()->create([
            'invoice_number' => 'RESERVE-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => 'Reservation Test Buyer',
            'payer_email' => 'reservation-buyer@aec.test',
            'payer_phone' => '+8801712345678',
            'subtotal_amount' => 1200,
            'discount_amount' => 0,
            'net_amount' => 1200,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
    }

    public function test_invoice_allows_only_one_active_session_across_gateways_and_reuses_same_idempotency_key(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $first = $service->reserve(
            $invoice,
            'gateway-stripe',
            'STRP',
            ['payment_method_id' => 'pm_original_test_method'],
            'stripe-request-001',
        );

        $sameRequest = $service->reserve(
            $invoice,
            'gateway-stripe',
            'STRP',
            ['payment_method_id' => 'pm_original_test_method'],
            'stripe-request-001',
        );
        $this->assertTrue($sameRequest['reused']);
        $this->assertSame($first['transaction']->id, $sameRequest['transaction']->id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['transaction']->metadata['request_fingerprint']);
        $this->assertArrayNotHasKey('payment_method_id', $first['transaction']->metadata);

        try {
            $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'paypal-request-001');
            $this->fail('A different gateway must not create a second chargeable session for the same invoice.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('active or ambiguous payment session', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }

    public function test_idempotency_fingerprint_normalizes_parameter_order_and_whitespace(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $first = $service->reserve(
            $invoice,
            'gateway-stripe',
            'STRP',
            [
                'payment_method_id' => 'pm_normalized_method',
                'options' => ['channel' => ' hosted ', 'locale' => ' en '],
            ],
            'stripe-normalized-key',
        );

        $replay = $service->reserve(
            $invoice,
            'gateway-stripe',
            'STRP',
            [
                'options' => ['locale' => 'en', 'channel' => 'hosted'],
                'payment_method_id' => 'pm_normalized_method',
            ],
            'stripe-normalized-key',
        );

        $this->assertTrue($replay['reused']);
        $this->assertSame($first['transaction']->id, $replay['transaction']->id);
        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }

    public function test_same_idempotency_key_with_different_provider_parameters_returns_conflict(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $original = $service->reserve(
            $invoice,
            'gateway-stripe',
            'STRP',
            ['payment_method_id' => 'pm_original_test_method'],
            'stripe-immutable-key',
        );

        try {
            $service->reserve(
                $invoice,
                'gateway-stripe',
                'STRP',
                ['payment_method_id' => 'pm_different_test_method'],
                'stripe-immutable-key',
            );
            $this->fail('A reused idempotency key with a different payment method must return HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(
            1,
            DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count(),
            'A conflicting replay must not create another payment transaction.',
        );
        $this->assertSame(
            $original['transaction']->id,
            DB::table('payment_transactions')->where('invoice_id', $invoice->id)->value('id'),
        );
    }

    public function test_ambiguous_provider_outcome_keeps_invoice_reserved_until_explicit_rejection_or_reconciliation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $reservation = $service->reserve($invoice, 'gateway-sslcommerz', 'SSL');
        $unknown = $service->markUnknown($reservation['transaction'], 'transport_timeout');

        $this->assertSame('initiated', $unknown->status);
        $this->assertSame('unknown', $unknown->metadata['session_outcome']);
        $this->assertTrue((bool) $unknown->metadata['manual_reconciliation_required']);

        try {
            $service->reserve($invoice, 'gateway-stripe', 'STRP');
            $this->fail('A timeout does not prove that the provider failed to create a chargeable session.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('active or ambiguous payment session', $exception->getMessage());
        }

        $released = $service->markRejected($unknown, 'provider_definitive_4xx');
        $this->assertSame('failed', $released->status);
        $next = $service->reserve($invoice, 'gateway-stripe', 'STRP');
        $this->assertFalse($next['reused']);
        $this->assertSame('gateway-stripe', $next['transaction']->gateway_slug);
    }

    public function test_terminal_idempotency_key_cannot_be_reused_for_a_new_charge_attempt(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $reservation = $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'paypal-terminal-key');
        $service->markRejected($reservation['transaction'], 'provider_definitive_4xx');
        $invoice->update(['paid_amount' => 1200, 'status' => 'paid']);

        try {
            $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'paypal-terminal-key');
            $this->fail('A replay after the invoice balance reaches zero must return HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('failed', $reservation['transaction']->fresh()->status);
        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }

    public function test_same_idempotency_key_with_changed_payable_balance_returns_conflict(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $metadata = ['fingerprint_parameters' => ['channel' => 'hosted']];
        $original = $service->reserve($invoice, 'gateway-paypal', 'PYPL', $metadata, 'paypal-balance-key');

        $invoice->update(['paid_amount' => 300]);

        try {
            $service->reserve($invoice->fresh(), 'gateway-paypal', 'PYPL', $metadata, 'paypal-balance-key');
            $this->fail('A replay after the payable amount changes from 1,200 to 900 must return HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(
            $original['transaction']->id,
            DB::table('payment_transactions')->where('invoice_id', $invoice->id)->value('id'),
        );
        $this->assertSame(1200.00, (float) $original['transaction']->fresh()->amount);
    }

    public function test_paypal_same_key_rejects_changed_invoice_reference_before_second_order_request(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-paypal']);

        app(PluginManager::class)->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-reservation-client',
            'client_secret' => 'paypal-reservation-secret',
            'sandbox_mode' => '1',
        ], ['client_secret']);

        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'paypal-fingerprint-access-token', 'token_type' => 'Bearer', 'expires_in' => 300], 200);
            }
            if (str_ends_with($path, '/v2/checkout/orders')) {
                return Http::response([
                    'id' => 'ORDER-FINGERPRINT-001',
                    'status' => 'CREATED',
                    'links' => [[
                        'rel' => 'approve',
                        'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-FINGERPRINT-001',
                    ]],
                ], 201);
            }

            return Http::response(['message' => 'Unexpected PayPal request'], 500);
        });

        $invoice = $this->createInvoice();
        $service = app(PaypalGatewayService::class);
        $service->createOrder($invoice, 'paypal-request-fingerprint-001');

        DB::table('fee_invoices')->where('id', $invoice->id)->update([
            'invoice_number' => 'RESERVE-CHANGED-' . strtoupper(bin2hex(random_bytes(3))),
        ]);

        try {
            $service->createOrder($invoice->fresh(), 'paypal-request-fingerprint-001');
            $this->fail('A same-key replay with a changed PayPal invoice reference must return HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $orderRequests = Http::recorded()->filter(
            fn (array $record): bool => str_ends_with((string) parse_url($record[0]->url(), PHP_URL_PATH), '/v2/checkout/orders')
        )->count();
        $this->assertSame(1, $orderRequests);
        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }

    public function test_sslcommerz_same_key_rejects_changed_payer_before_second_session_request(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-sslcommerz']);

        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'aec_fingerprint_store',
            'store_password' => 'ssl_fingerprint_secret',
            'sandbox_mode' => '1',
        ], ['store_password']);

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'sessionkey' => 'SSL-FINGERPRINT-SESSION',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?SESSIONKEY=SSL-FINGERPRINT-SESSION',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected SSLCOMMERZ request'], 500);
        });

        $invoice = $this->createInvoice();
        $service = app(SslcommerzGatewayService::class);
        $service->initiateHostedCheckout($invoice, 'ssl-payer-fingerprint-001');

        $invoice->update(['payer_email' => 'changed-payer@aec.test']);

        try {
            $service->initiateHostedCheckout($invoice->fresh(), 'ssl-payer-fingerprint-001');
            $this->fail('A same-key replay with changed payer details must return HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $sessionRequests = Http::recorded()->filter(
            fn (array $record): bool => str_ends_with((string) parse_url($record[0]->url(), PHP_URL_PATH), '/gwprocess/v4/api.php')
        )->count();
        $this->assertSame(1, $sessionRequests);
        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }

    public function test_mysql_reservation_locks_invoice_before_checking_active_payment_transactions(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $lockSequence = [];
        DB::listen(function ($query) use (&$lockSequence): void {
            $sql = strtolower(preg_replace('/\\s+/', ' ', $query->sql));
            if (str_contains($sql, 'fee_invoices') && str_contains($sql, 'for update')) {
                $lockSequence[] = 'invoice';
            }
            if (str_contains($sql, 'payment_transactions') && str_contains($sql, 'for update')) {
                $lockSequence[] = 'transactions';
            }
        });

        app(InvoicePaymentSessionReservationService::class)->reserve($invoice, 'gateway-sslcommerz', 'SSL');

        if (DB::connection()->getDriverName() === 'mysql') {
            $invoiceLock = array_search('invoice', $lockSequence, true);
            $transactionLock = array_search('transactions', $lockSequence, true);
            $this->assertNotFalse($invoiceLock, 'Reservation must lock the invoice row.');
            $this->assertNotFalse($transactionLock, 'Reservation must lock active payment transaction rows.');
            $this->assertLessThan($transactionLock, $invoiceLock, 'Invoice lock must precede active-session lookup.');
        }
    }

    public function test_ambiguous_sslcommerz_response_blocks_a_second_paypal_session(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'gateway-sslcommerz', 'gateway-paypal']);

        $plugins = app(PluginManager::class);
        $plugins->saveSettings('gateway-sslcommerz', [
            'store_id' => 'aec_reservation_store',
            'store_password' => 'ssl_reservation_secret',
            'sandbox_mode' => '1',
        ], ['store_password']);
        $plugins->saveSettings('gateway-paypal', [
            'client_id' => 'paypal-reservation-client',
            'client_secret' => 'paypal-reservation-secret',
            'sandbox_mode' => '1',
        ], ['client_secret']);

        $invoice = $this->createInvoice();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/gwprocess/v4/api.php')) {
                return Http::response(['message' => 'Provider temporarily unavailable'], 503);
            }

            return Http::response(['message' => 'Unexpected second provider request'], 500);
        });

        try {
            app(SslcommerzGatewayService::class)->initiateHostedCheckout($invoice, 'ssl-ambiguous-001');
            $this->fail('An unsuccessful ambiguous SSLCOMMERZ response must not be treated as a released reservation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unsuccessful HTTP response', $exception->getMessage());
        }

        $transaction = PaymentTransaction::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('gateway-sslcommerz', $transaction->gateway_slug);
        $this->assertSame('initiated', $transaction->status);
        $this->assertSame('unknown', $transaction->metadata['session_outcome']);
        $this->assertTrue((bool) $transaction->metadata['manual_reconciliation_required']);

        try {
            app(PaypalGatewayService::class)->createOrder($invoice, 'paypal-ambiguous-002');
            $this->fail('PayPal must not create another chargeable session while SSLCOMMERZ outcome is ambiguous.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('active or ambiguous payment session', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }



    public function test_late_provider_response_cannot_regress_a_settled_or_failed_transaction(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);

        $invoice = $this->createInvoice();
        $service = app(InvoicePaymentSessionReservationService::class);
        $reservation = $service->reserve($invoice, 'gateway-stripe', 'STRP', [], 'late-callback-key');
        $transaction = $reservation['transaction'];

        // Model the webhook/capture callback winning before the synchronous
        // checkout response handler persists its provider response.
        $transaction->update([
            'status' => 'settled',
            'external_reference' => 'provider-capture-already-settled',
            'settled_at' => now(),
        ]);

        $created = $service->markCreated(
            $transaction,
            ['provider_session_id' => 'late-session-response'],
            'initiated',
            'late-external-reference'
        );
        $this->assertSame('settled', $created->status);
        $this->assertSame('provider-capture-already-settled', $created->external_reference);

        $unknown = $service->markUnknown($created, 'late_timeout');
        $this->assertSame('settled', $unknown->status);
        $this->assertSame('provider-capture-already-settled', $unknown->external_reference);

        $rejected = $service->markRejected($unknown, 'late_provider_4xx');
        $this->assertSame('settled', $rejected->status);
        $this->assertSame('provider-capture-already-settled', $rejected->external_reference);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => 'settled',
            'external_reference' => 'provider-capture-already-settled',
        ]);

        $failedReservation = $service->reserve($this->createInvoice(), 'gateway-paypal', 'PYPL');
        $failedReservation['transaction']->update(['status' => 'failed']);
        $lateCreate = $service->markCreated($failedReservation['transaction'], ['paypal_order_id' => 'late-order'], 'initiated');
        $this->assertSame('failed', $lateCreate->status);
    }



    public function test_mysql_second_connection_waits_for_invoice_reservation_then_observes_the_first_session(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This row-lock contention regression requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);
        $invoice = $this->createInvoice();

        $firstConnection = DB::connection('mysql');
        $secondName = 'mysql_red_team_concurrency';
        config(['database.connections.' . $secondName => config('database.connections.mysql')]);
        DB::purge($secondName);
        $secondConnection = DB::connection($secondName);
        $secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $defaultConnection = DB::getDefaultConnection();
        $service = app(InvoicePaymentSessionReservationService::class);
        $firstConnection->beginTransaction();

        try {
            DB::setDefaultConnection('mysql');
            $firstReservation = $service->reserve($invoice, 'gateway-sslcommerz', 'SSL', [], 'first-connection-key');

            // The first connection still owns the outer transaction and invoice
            // row lock after reserve() commits its nested transaction.
            DB::setDefaultConnection($secondName);
            try {
                $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'second-connection-key');
                $this->fail('A second connection must wait on the shared invoice lock.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('lock wait timeout', strtolower($exception->getMessage()));
            }

            DB::setDefaultConnection('mysql');
            $firstConnection->commit();

            DB::setDefaultConnection($secondName);
            try {
                $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'second-connection-key');
                $this->fail('After the first commit, the second connection must observe the active reservation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('active or ambiguous payment session', $exception->getMessage());
            }

            $this->assertSame(
                1,
                $secondConnection->table('payment_transactions')->where('invoice_id', $invoice->id)->count()
            );
            $this->assertSame($firstReservation['transaction']->id,
                $secondConnection->table('payment_transactions')->where('invoice_id', $invoice->id)->value('id'));
        } finally {
            DB::setDefaultConnection('mysql');
            if ($firstConnection->transactionLevel() > 0) {
                $firstConnection->rollBack();
            }
            DB::setDefaultConnection($defaultConnection);
            DB::purge($secondName);
        }
    }


}
