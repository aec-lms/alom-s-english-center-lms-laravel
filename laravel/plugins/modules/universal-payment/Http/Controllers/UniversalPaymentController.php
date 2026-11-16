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
        $enabledRaw = $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,bkash,nagad,rocket,card,bank_cash');
        $config = [
            'default_currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT'),
            'enabled_gateways' => array_filter(array_map('trim', explode(',', (string) $enabledRaw))),
            'sslcommerz_store_id' => $this->plugins->getSetting('universal-payment', 'sslcommerz_store_id', ''),
            'nagad_merchant_id' => $this->plugins->getSetting('universal-payment', 'nagad_merchant_id', ''),
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
            'sslcommerz_store_id' => ['nullable', 'string', 'max:120'],
            'sslcommerz_store_password' => ['nullable', 'string', 'max:255'],
            'bkash_app_key' => ['nullable', 'string', 'max:255'],
            'bkash_app_secret' => ['nullable', 'string', 'max:255'],
            'nagad_merchant_id' => ['nullable', 'string', 'max:120'],
            'stripe_secret_key' => ['nullable', 'string', 'max:255'],
        ]);

        $values = [
            'default_currency' => $validated['default_currency'],
            'enabled_gateways' => implode(',', $validated['gateways'] ?? ['sslcommerz', 'bkash', 'nagad', 'bank_cash']),
            'sslcommerz_store_id' => trim((string) ($validated['sslcommerz_store_id'] ?? '')),
            'nagad_merchant_id' => trim((string) ($validated['nagad_merchant_id'] ?? '')),
        ];

        $secrets = [];
        foreach (['sslcommerz_store_password', 'bkash_app_key', 'bkash_app_secret', 'stripe_secret_key'] as $secKey) {
            if (!empty($validated[$secKey])) {
                $values[$secKey] = trim((string) $validated[$secKey]);
                $secrets[] = $secKey;
            }
        }

        $this->plugins->saveSettings('universal-payment', $values, $secrets, auth()->id());

        return back()->with('status', 'Universal Payment Gateway configuration saved securely.');
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_contact' => ['nullable', 'string', 'max:120'],
            'item_title' => ['required', 'string', 'max:200'],
            'gateway' => ['required', 'in:bank_cash'],
            'amount' => ['required', 'numeric', 'min:1'],
            'external_trx_id' => ['nullable', 'string', 'max:120'],
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
            'status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->audit->log('universal_payment.recorded', 'universal_payment', $tx->reference, ['amount' => $tx->amount, 'gateway' => $tx->gateway]);

        return back()->with('status', "Transaction '{$tx->reference}' recorded ({$tx->gateway} · {$tx->status}).");
    }

    public function updateStatus(Request $request, UniversalPaymentTransaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
        ]);

        if ($validated['status'] === 'verified') {
            if ($transaction->gateway !== 'bank_cash') {
                abort(422, 'Online gateway transactions must be verified by the provider webhook.');
            }
            if (trim((string) $transaction->external_trx_id) === '') {
                abort(422, 'A bank receipt or cash voucher reference is required before verification.');
            }
        }

        $transaction->update([
            'status' => $validated['status'],
            'verified_by' => auth()->id(),
            'verified_at' => now(),
        ]);

        $this->audit->log('universal_payment.status_updated', 'universal_payment', $transaction->reference, ['status' => $validated['status']]);

        return back()->with('status', "Transaction '{$transaction->reference}' marked {$validated['status']}.");
    }

    public function publicCheckout(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'gateway' => ['required', 'in:sslcommerz,card,paypal'],
            'payment_method_id' => ['nullable', 'string', 'max:160'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();
        abort_unless($actor, 401);

        $invoice = FeeInvoice::query()->with('student')->findOrFail((int) $validated['invoice_id']);
        $isFinanceOperator = $actor->hasRole('admin') || $actor->hasRole('accountant');
        $isInvoiceOwner = (int) ($invoice->student?->user_id ?? 0) === (int) $actor->id;
        abort_unless($isFinanceOperator || $isInvoiceOwner, 403, 'You are not authorized to pay this invoice.');

        $enabledRaw = (string) $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,card,paypal');
        $enabledGateways = array_values(array_filter(array_map('trim', explode(',', $enabledRaw))));
        abort_unless(in_array($validated['gateway'], $enabledGateways, true), 403, 'This checkout provider is not enabled by the LMS administrator.');

        $gatewaySlug = match ($validated['gateway']) {
            'sslcommerz' => 'gateway-sslcommerz',
            'card' => 'gateway-stripe',
            'paypal' => 'gateway-paypal',
        };

        try {
            $result = $this->orchestrator->initiate($gatewaySlug, $invoice, [
                'payment_method_id' => $validated['payment_method_id'] ?? '',
                'idempotency_key' => $validated['idempotency_key'] ?? null,
            ]);
        } catch (\RuntimeException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return back()->withErrors(['gateway' => $exception->getMessage()]);
        }

        if (!empty($result['redirect_url'])) {
            return redirect()->away($result['redirect_url']);
        }

        return response()->json($result, 201);
    }
}
