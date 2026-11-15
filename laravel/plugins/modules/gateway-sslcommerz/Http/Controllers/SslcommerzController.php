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
        // Strictly reject direct card fields in hosted SSLCOMMERZ checkout
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc'])) {
            abort(422, 'Raw card details are prohibited on SSLCOMMERZ Hosted Checkout.');
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
        ]);

        $invoice = FeeInvoice::query()->findOrFail((int) $validated['invoice_id']);
        $session = $this->gateway->initiateHostedCheckout($invoice);

        return response()->json([
            'gateway' => 'gateway-sslcommerz',
            'transaction_ref' => $session['transaction']->transaction_ref,
            'hosted_endpoint' => $session['hosted_endpoint'],
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
}
