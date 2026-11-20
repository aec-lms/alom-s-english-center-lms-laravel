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
use Tests\TestCase;

class InvoicePaymentSessionReservationTest extends TestCase
{
    private function createInvoice(): FeeInvoice
    {
        return FeeInvoice::query()->create([
            'invoice_number' => 'RESERVE-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => 'Reservation Test Buyer',
            'payer_email' => 'reservation-buyer@aec.test',
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
        $first = $service->reserve($invoice, 'gateway-stripe', 'STRP', [], 'stripe-request-001');

        $sameRequest = $service->reserve($invoice, 'gateway-stripe', 'STRP', [], 'stripe-request-001');
        $this->assertTrue($sameRequest['reused']);
        $this->assertSame($first['transaction']->id, $sameRequest['transaction']->id);

        try {
            $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'paypal-request-001');
            $this->fail('A different gateway must not create a second chargeable session for the same invoice.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('active or ambiguous payment session', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
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

        $replay = $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'paypal-terminal-key');
        $this->assertTrue($replay['reused']);
        $this->assertSame($reservation['transaction']->id, $replay['transaction']->id);
        $this->assertSame('failed', $replay['transaction']->status);
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


}
