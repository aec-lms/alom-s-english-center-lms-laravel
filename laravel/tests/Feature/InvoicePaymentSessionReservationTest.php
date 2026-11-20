<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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

        try {
            $service->reserve($invoice, 'gateway-paypal', 'PYPL', [], 'paypal-terminal-key');
            $this->fail('A terminal transaction idempotency key must remain bound to its original attempt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('terminal payment transaction', $exception->getMessage());
        }
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
}
