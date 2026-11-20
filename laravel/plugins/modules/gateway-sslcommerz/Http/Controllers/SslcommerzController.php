<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;

class SslcommerzController extends Controller
{
    public function __construct(private readonly SslcommerzGatewayService $gateway, private readonly PluginManager $plugins) {}

    public function initiate(Request $request): JsonResponse
    {
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc'])) {
            abort(422, 'Raw card details are prohibited on SSLCOMMERZ Hosted Checkout.');
        }

        abort_unless(
            $this->plugins->isEnabled('fees-management')
                && Schema::hasTable('fee_invoices')
                && $this->plugins->isEnabled('payment-management')
                && Schema::hasTable('payment_transactions'),
            409,
            'Enable Fees and Payment Management before using this gateway.'
        );

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $studentIntegration = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        $invoiceQuery = FeeInvoice::query();
        if ($studentIntegration) {
            $invoiceQuery->with('student.user');
        }
        $invoice = $invoiceQuery->findOrFail((int) $validated['invoice_id']);
        $user = $request->user();

        $isInvoiceOwner = $studentIntegration
            ? (int) ($invoice->student?->user_id ?? 0) === (int) $user->id
            : strtolower((string) $invoice->payer_email) === strtolower((string) $user->email);
        $isFinanceOperator = $user->hasRole('admin') || $user->hasRole('accountant')
            || $user->hasPermission('gateway.sslcommerz.use') || $user->hasPermission('payments.manage');
        $isAssignedBranchManager = $user->hasRole('branch_manager')
            && (int) $user->branch_id > 0
            && (int) $invoice->branch_id === (int) $user->branch_id;
        abort_unless($isInvoiceOwner || $isFinanceOperator || $isAssignedBranchManager, 403,
            'You are not authorized to pay this invoice.');

        $session = $this->gateway->initiateHostedCheckout($invoice, $validated['idempotency_key'] ?? $request->header('Idempotency-Key'));

        return response()->json([
            'gateway' => 'gateway-sslcommerz',
            'transaction_ref' => $session['transaction']->transaction_ref,
            'redirect_url' => $session['hosted_endpoint'],
            'status' => $session['transaction']->status,
        ]);
    }

    public function ipn(Request $request): JsonResponse
    {
        $tx = $this->gateway->verifyAndSettleIpn($request->all());

        return response()->json([
            'status' => $tx->status,
            'transaction_ref' => $tx->transaction_ref,
        ]);
    }

    public function success(Request $request): JsonResponse
    {
        return $this->ipn($request);
    }

    public function failed(Request $request): JsonResponse
    {
        $validated = $request->validate(['tran_id' => ['required', 'string', 'max:30']]);
        $tx = $this->gateway->markUnsuccessful($validated['tran_id'], 'failed');

        return response()->json(['status' => $tx->status, 'transaction_ref' => $tx->transaction_ref]);
    }

    public function cancelled(Request $request): JsonResponse
    {
        $validated = $request->validate(['tran_id' => ['required', 'string', 'max:30']]);
        $tx = $this->gateway->markUnsuccessful($validated['tran_id'], 'cancelled');

        return response()->json(['status' => $tx->status, 'transaction_ref' => $tx->transaction_ref]);
    }
}
