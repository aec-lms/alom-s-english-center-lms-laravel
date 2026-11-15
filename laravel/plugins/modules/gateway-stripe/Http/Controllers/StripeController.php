<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayStripe\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewayStripe\Services\StripeGatewayService;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;

class StripeController extends Controller
{
    public function __construct(
        private readonly StripeGatewayService $stripe,
        private readonly PluginManager $plugins,
    ) {}

    public function index(): View
    {
        $configured = (string) $this->plugins->getSetting('gateway-stripe', 'secret_key', '') !== '';
        $sandbox = $this->plugins->getSetting('gateway-stripe', 'sandbox_mode', '1') === '1';
        $transactions = PaymentTransaction::query()->where('gateway_slug', 'gateway-stripe')->latest()->limit(20)->get();

        return view('gateway-stripe::index', compact('configured', 'sandbox', 'transactions'));
    }

    public function checkout(Request $request): JsonResponse
    {
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc', 'security_code'])) {
            abort(422, 'Raw PAN and CVV are strictly prohibited. Use a Stripe tokenized payment_method_token.');
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'payment_token' => ['required', 'string', 'min:8', 'max:180'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $invoice = FeeInvoice::query()->findOrFail((int) $validated['invoice_id']);
        $tx = $this->stripe->initiateTokenizedCheckout(
            $invoice,
            $validated['payment_token'],
            $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        );

        return response()->json([
            'gateway' => 'gateway-stripe',
            'transaction_ref' => $tx->transaction_ref,
            'amount' => $tx->amount,
            'currency' => $tx->currency,
            'status' => $tx->status,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $sig = (string) $request->header('Stripe-Signature', '');
        $tx = $this->stripe->verifyAndSettleWebhook($request->getContent(), $sig, $request->all());

        return response()->json([
            'gateway' => 'gateway-stripe',
            'status' => $tx->status,
            'transaction_ref' => $tx->transaction_ref,
        ]);
    }
}
