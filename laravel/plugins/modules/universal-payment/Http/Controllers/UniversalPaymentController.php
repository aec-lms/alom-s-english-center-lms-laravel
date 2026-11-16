<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\UniversalPaymentOrchestrator;

class UniversalPaymentController extends Controller
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
        private readonly UniversalPaymentOrchestrator $orchestrator,
    ) {}

    public function index(): View
    {
        $enabledRaw = $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,stripe,paypal');
        $config = [
            'default_currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT'),
            'enabled_gateways' => array_filter(array_map('trim', explode(',', (string) $enabledRaw))),
        ];
        $transactions = Schema::hasTable('universal_payment_transactions')
            ? UniversalPaymentTransaction::query()->latest()->limit(50)->get()
            : collect();

        return view('universal-payment::index', compact('config', 'transactions'));
    }

    public function saveGateways(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_currency' => ['required', 'in:BDT,USD,EUR,GBP'],
            'gateways' => ['nullable', 'array'],
            'gateways.*' => ['required', 'in:sslcommerz,stripe,paypal'],
        ]);

        $values = [
            'default_currency' => $validated['default_currency'],
            'enabled_gateways' => implode(',', array_unique($validated['gateways'] ?? [])),
        ];

        $this->plugins->saveSettings('universal-payment', $values, [], auth()->id());

        return back()->with('status', 'Universal Payment settings saved.');
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_contact' => ['nullable', 'string', 'max:120'],
            'item_title' => ['required', 'string', 'max:200'],
            'gateway' => ['required', 'in:sslcommerz,bkash,nagad,rocket,card,paypal,bank_cash'],
            'amount' => ['required', 'numeric', 'min:1'],
            'external_trx_id' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'in:pending,verified,refunded'],
        ]);

        $tx = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-' . strtoupper(Str::random(8)),
            'payer_name' => $validated['payer_name'],
            'payer_contact' => $validated['payer_contact'] ?? null,
            'item_title' => $validated['item_title'],
            'gateway' => $validated['gateway'],
            'currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT') ?: 'BDT',
            'amount' => $validated['amount'],
            'external_trx_id' => $validated['external_trx_id'] ?? null,
            'status' => $validated['status'],
            'verified_by' => $validated['status'] === 'verified' ? auth()->id() : null,
            'verified_at' => $validated['status'] === 'verified' ? now() : null,
        ]);

        $this->audit->log('universal_payment.recorded', 'universal_payment', $tx->reference, ['amount' => $tx->amount, 'gateway' => $tx->gateway]);

        return back()->with('status', "Transaction '{$tx->reference}' recorded ({$tx->gateway} · {$tx->status}).");
    }

    public function updateStatus(Request $request, UniversalPaymentTransaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,verified,refunded,rejected'],
        ]);

        $transaction->update([
            'status' => $validated['status'],
            'verified_by' => auth()->id(),
            'verified_at' => now(),
        ]);

        $this->audit->log('universal_payment.status_updated', 'universal_payment', $transaction->reference, ['status' => $validated['status']]);

        return back()->with('status', "Transaction '{$transaction->reference}' marked {$validated['status']}.");
    }

    public function publicCheckout(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'gateway' => ['required', 'in:sslcommerz,stripe,paypal'],
            'payment_method_id' => ['required_if:gateway,stripe', 'nullable', 'string', 'max:180'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $invoice = FeeInvoice::query()
            ->whereHas('student', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail((int) $validated['invoice_id']);

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0 || $invoice->status === 'paid') {
            abort(422, 'This invoice is already fully paid.');
        }

        $gatewaySlug = match ($validated['gateway']) {
            'sslcommerz' => 'gateway-sslcommerz',
            'stripe' => 'gateway-stripe',
            'paypal' => 'gateway-paypal',
        };

        $result = $this->orchestrator->initiate($gatewaySlug, $invoice, [
            'payment_method_id' => $validated['payment_method_id'] ?? '',
            'idempotency_key' => $validated['idempotency_key'] ?? null,
        ]);

        if (!empty($result['redirect_url'])) {
            return redirect()->away($result['redirect_url']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'gateway' => $result['gateway'],
                'transaction_ref' => $result['transaction_ref'],
                'payment_intent_id' => $result['payment_intent_id'] ?? null,
                'client_secret' => $result['client_secret'] ?? null,
            ]);
        }

        return back()->with('status', 'Payment intent created. Complete the secure payment step to settle your invoice.');
    }
}
