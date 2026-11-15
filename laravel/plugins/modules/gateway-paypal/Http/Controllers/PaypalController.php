<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;

class PaypalController extends Controller
{
    public function __construct(
        private readonly PaypalGatewayService $paypal,
        private readonly PluginManager $plugins,
    ) {}

    public function index(): View
    {
        $configured = (string) $this->plugins->getSetting('gateway-paypal', 'client_secret', '') !== '';
        $sandbox = $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1';
        $transactions = PaymentTransaction::query()->where('gateway_slug', 'gateway-paypal')->latest()->limit(20)->get();

        return view('gateway-paypal::index', compact('configured', 'sandbox', 'transactions'));
    }

    public function createOrder(Request $request): JsonResponse
    {
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc', 'security_code'])) {
            abort(422, 'Raw PAN and CVV are strictly forbidden on PayPal hosted checkout.');
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $invoice = FeeInvoice::query()->findOrFail((int) $validated['invoice_id']);
        $result = $this->paypal->createOrder(
            $invoice,
            $validated['idempotency_key'] ?? $request->header('PayPal-Request-Id')
        );

        return response()->json([
            'gateway' => 'gateway-paypal',
            'transaction_ref' => $result['transaction']->transaction_ref,
            'paypal_order_id' => $result['paypal_order_id'],
            'approval_url' => $result['approval_url'],
            'status' => $result['transaction']->status,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $sig = (string) $request->header('X-PayPal-Signature', '');
        $tx = $this->paypal->verifyAndSettleWebhook($request->getContent(), $sig, $request->all());

        return response()->json([
            'gateway' => 'gateway-paypal',
            'status' => $tx->status,
            'transaction_ref' => $tx->transaction_ref,
        ]);
    }
}
