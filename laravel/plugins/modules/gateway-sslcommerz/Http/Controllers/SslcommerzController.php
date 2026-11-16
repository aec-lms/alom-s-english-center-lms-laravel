<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;

class SslcommerzController extends Controller
{
    public function __construct(private readonly SslcommerzGatewayService $gateway) {}

    public function initiate(Request $request): JsonResponse
    {
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc'])) {
            abort(422, 'Raw card details are prohibited on SSLCOMMERZ Hosted Checkout.');
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
        ]);

        $invoice = FeeInvoice::query()->with('student.user')->findOrFail((int) $validated['invoice_id']);
        $user = $request->user();

        if ($user->hasRole('student')) {
            abort_unless((int) $invoice->student?->user_id === (int) $user->id, 403, 'Students can only pay their own invoices.');
        } elseif ($user->hasRole('branch_manager')) {
            abort_unless((int) $user->branch_id > 0 && (int) $invoice->branch_id === (int) $user->branch_id, 403, 'Invoice is outside your assigned branch.');
        } elseif (!$user->hasPermission('gateway.sslcommerz.use') && !$user->hasPermission('payments.manage')) {
            abort(403, 'You are not allowed to initiate SSLCOMMERZ payments.');
        }

        $session = $this->gateway->initiateHostedCheckout($invoice);

        return response()->json([
            'gateway' => 'gateway-sslcommerz',
            'transaction_ref' => $session['transaction']->transaction_ref,
            'redirect_url' => $session['hosted_endpoint'],
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
