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
    public function __construct(private readonly StripeGatewayService $stripe, private readonly PluginManager $plugins) {}

    public function index(): View
    {
        $configured = (string) $this->plugins->getSetting('gateway-stripe', 'secret_key', '') !== '';
        $sandbox = str_starts_with((string) $this->plugins->getSetting('gateway-stripe', 'secret_key', ''), 'sk_test_');
        $transactions = PaymentTransaction::query()->where('gateway_slug', 'gateway-stripe')->latest()->limit(20)->get();
        return view('gateway-stripe::index', compact('configured', 'sandbox', 'transactions'));
    }

    public function checkout(Request $request): JsonResponse
    {
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc', 'security_code'])) {
            abort(422, 'Raw PAN and CVV are strictly prohibited. Use Stripe.js to create a PaymentMethod.');
        }
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'payment_token' => ['required', 'string', 'starts_with:pm_', 'max:180'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        $invoice = FeeInvoice::query()->with('student.user')->findOrFail((int) $validated['invoice_id']);
        $this->assertCanPayInvoice($request, $invoice);
        $result = $this->stripe->initiateTokenizedCheckout(
            $invoice,
            $validated['payment_token'],
            $validated['idempotency_key'] ?? $request->header('Idempotency-Key')
        );
        return response()->json([
            'gateway' => 'gateway-stripe',
            'transaction_ref' => $result['transaction']->transaction_ref,
            'payment_intent_id' => $result['payment_intent_id'],
            'client_secret' => $result['client_secret'],
            'amount' => $result['transaction']->amount,
            'currency' => $result['transaction']->currency,
            'status' => $result['transaction']->status,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $tx = $this->stripe->verifyAndSettleWebhook($request->getContent(), (string) $request->header('Stripe-Signature', ''));
        return response()->json(['gateway' => 'gateway-stripe', 'status' => $tx->status, 'transaction_ref' => $tx->transaction_ref]);
    }

    private function assertCanPayInvoice(Request $request, FeeInvoice $invoice): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $isFinanceOperator = $user->hasRole('admin')
            || $user->hasRole('accountant')
            || $user->hasPermission('payments.manage')
            || $user->hasPermission('payments.stripe.manage');
        $isInvoiceOwner = (int) ($invoice->student?->user_id ?? 0) === (int) $user->id;
        $isAssignedBranchManager = $user->hasRole('branch_manager')
            && (int) $user->branch_id > 0
            && (int) $invoice->branch_id === (int) $user->branch_id;

        abort_unless($isFinanceOperator || $isInvoiceOwner || $isAssignedBranchManager, 403,
            'You are not authorized to pay this invoice.');
    }
}
