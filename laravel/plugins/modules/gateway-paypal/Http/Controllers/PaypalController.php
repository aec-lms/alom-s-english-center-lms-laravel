<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;

class PaypalController extends Controller
{
    public function __construct(private readonly PaypalGatewayService $paypal, private readonly PluginManager $plugins) {}

    public function index(): View
    {
        $this->assertPaymentIntegrationsReady();
        $configured = (string) $this->plugins->getSetting('gateway-paypal', 'client_secret', '') !== '';
        $sandbox = $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1';
        $transactions = PaymentTransaction::query()->where('gateway_slug', 'gateway-paypal')->latest()->limit(20)->get();
        return view('gateway-paypal::index', compact('configured', 'sandbox', 'transactions'));
    }

    public function createOrder(Request $request): JsonResponse
    {
        $this->assertPaymentIntegrationsReady();
        if ($request->hasAny(['card_number', 'pan', 'cvv', 'cvc', 'security_code'])) {
            abort(422, 'Raw card details are prohibited on PayPal hosted checkout.');
        }
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        $studentIntegration = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        $invoiceQuery = FeeInvoice::query();
        if ($studentIntegration) { $invoiceQuery->with('student.user'); }
        $invoice = $invoiceQuery->findOrFail((int) $validated['invoice_id']);
        $this->assertCanPayInvoice($request, $invoice, $studentIntegration);
        $result = $this->paypal->createOrder($invoice, $validated['idempotency_key'] ?? $request->header('PayPal-Request-Id'));
        return response()->json([
            'gateway' => 'gateway-paypal',
            'transaction_ref' => $result['transaction']->transaction_ref,
            'paypal_order_id' => $result['paypal_order_id'],
            'approval_url' => $result['approval_url'],
            'status' => $result['transaction']->status,
            'session_outcome' => $result['transaction']->metadata['session_outcome'] ?? null,
        ]);
    }

    public function captureReturn(Request $request): JsonResponse
    {
        $this->assertPaymentIntegrationsReady();
        $orderId = (string) $request->query('token', '');
        abort_if($orderId === '', 422, 'PayPal order token is missing.');
        $tx = PaymentTransaction::query()->where('gateway_slug', 'gateway-paypal')->where('external_reference', $orderId)->firstOrFail();
        $studentIntegration = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        $invoice = FeeInvoice::query()->findOrFail($tx->invoice_id);
        if ($studentIntegration) { $invoice->load('student'); }
        $this->assertCanPayInvoice($request, $invoice, $studentIntegration);
        $settled = $this->paypal->captureOrder($tx->transaction_ref);
        return response()->json(['gateway' => 'gateway-paypal', 'status' => $settled->status, 'transaction_ref' => $settled->transaction_ref]);
    }

    public function cancelReturn(): JsonResponse
    {
        return response()->json(['gateway' => 'gateway-paypal', 'status' => 'cancelled', 'message' => 'Payment was cancelled.']);
    }

    public function webhook(Request $request): JsonResponse
    {
        $this->assertPaymentIntegrationsReady();
        $headers = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];
        $tx = $this->paypal->verifyAndSettleWebhook($headers, $request->all());
        return response()->json(['gateway' => 'gateway-paypal', 'status' => $tx->status, 'transaction_ref' => $tx->transaction_ref]);
    }

    private function assertPaymentIntegrationsReady(): void
    {
        abort_unless(
            $this->plugins->isEnabled('fees-management')
                && Schema::hasTable('fee_invoices')
                && $this->plugins->isEnabled('payment-management')
                && Schema::hasTable('payment_transactions'),
            409,
            'Enable Fees and Payment Management before using this gateway.'
        );
    }

    private function assertCanPayInvoice(Request $request, FeeInvoice $invoice, bool $studentIntegration): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $isFinanceOperator = $user->hasRole('admin')
            || $user->hasRole('accountant')
            || $user->hasPermission('payments.manage')
            || $user->hasPermission('payments.paypal.manage');
        $isInvoiceOwner = $studentIntegration
            ? (int) ($invoice->student?->user_id ?? 0) === (int) $user->id
            : strtolower((string) $invoice->payer_email) === strtolower((string) $user->email);
        $isAssignedBranchManager = $user->hasRole('branch_manager')
            && (int) $user->branch_id > 0
            && (int) $invoice->branch_id === (int) $user->branch_id;

        abort_unless($isFinanceOperator || $isInvoiceOwner || $isAssignedBranchManager, 403,
            'You are not authorized to pay this invoice.');
    }
}
