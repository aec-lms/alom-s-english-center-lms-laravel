<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewayCard\Services\CardGatewayService;

class CardCheckoutController extends Controller
{
    public function __construct(private readonly CardGatewayService $gateway) {}

    public function checkout(Request $request): JsonResponse
    {
        // PCI-DSS strict guard: reject raw PAN, card_number, CVV, CVC, expiry
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc', 'security_code'])) {
            abort(422, 'Raw PAN and CVV are strictly forbidden. Use a processor-issued payment_token.');
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'payment_token' => ['required', 'string', 'min:10', 'max:160'],
            'card_brand' => ['required', 'in:visa,mastercard'],
            'last4' => ['required', 'digits:4'],
        ]);

        $invoice = FeeInvoice::query()->findOrFail((int) $validated['invoice_id']);
        try {
            $tx = $this->gateway->authorizeTokenizedCheckout(
                $invoice,
                $validated['payment_token'],
                $validated['card_brand'],
                $validated['last4']
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'gateway' => 'gateway-card',
            'transaction_ref' => $tx->transaction_ref,
            'card_brand' => $validated['card_brand'],
            'last4' => $validated['last4'],
            'status' => $tx->status,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $signature = (string) $request->header('X-Card-Signature', '');
        try {
            $tx = $this->gateway->verifyAndSettleWebhook($request->getContent(), $signature, $request->all());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'status' => $tx->status,
            'transaction_ref' => $tx->transaction_ref,
        ]);
    }
}
