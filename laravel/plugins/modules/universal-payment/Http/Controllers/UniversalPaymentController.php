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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $actor = auth()->user();
        $this->ensureAssignedBranch($actor);
        $enabledRaw = $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,card,paypal,bank_cash');
        $config = [
            'default_currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT'),
            'enabled_gateways' => array_filter(array_map('trim', explode(',', (string) $enabledRaw))),
            'allow_offline_payments' => $this->plugins->getSetting('universal-payment', 'allow_offline_payments', '1') === '1',
        ];
        $transactions = Schema::hasTable('universal_payment_transactions')
            ? UniversalPaymentTransaction::query()
                ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->latest()->limit(50)->get()
            : collect();

        return view('universal-payment::index', compact('config', 'transactions'));
    }

    public function saveGateways(Request $request): RedirectResponse
    {
        abort_if($request->user()?->hasRole('branch_manager'), 403, 'Only central finance administrators can change global gateway settings.');
        $validated = $request->validate([
            'default_currency' => ['required', 'in:BDT,USD,EUR,GBP'],
            'gateways' => ['nullable', 'array'],
            'gateways.*' => ['required', 'in:sslcommerz,card,paypal,bank_cash'],
            'allow_offline_payments' => ['required', 'boolean'],
        ]);

        $values = [
            'default_currency' => $validated['default_currency'],
            'enabled_gateways' => implode(',', $validated['gateways'] ?? []),
            'allow_offline_payments' => $validated['allow_offline_payments'] ? '1' : '0',
        ];

        $this->plugins->saveSettings('universal-payment', $values, [], auth()->id());

        return back()->with('status', 'Universal Payment Gateway configuration saved securely.');
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        abort_unless($this->plugins->getSetting('universal-payment', 'allow_offline_payments', '1') === '1', 403, 'Cash and bank-transfer receipts are disabled by the LMS administrator.');
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_contact' => ['nullable', 'string', 'max:120'],
            'item_title' => ['required', 'string', 'max:200'],
            'gateway' => ['required', 'in:bank_cash'],
            'amount' => ['required', 'numeric', 'min:1'],
            'external_trx_id' => ['required', 'string', 'max:120'],
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
            'branch_id' => $actor && $actor->hasRole('branch_manager') ? $actor->branch_id : null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->audit->log('universal_payment.recorded', 'universal_payment', $tx->reference, ['amount' => $tx->amount, 'gateway' => $tx->gateway, 'has_proof' => $proofPath !== null]);

        return back()->with('status', "Transaction '{$tx->reference}' recorded ({$tx->gateway} · {$tx->status}).");
    }

    public function downloadProof(Request $request, UniversalPaymentTransaction $transaction): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $transaction->branch_id) {
            abort(404);
        }

        abort_unless(is_string($transaction->proof_path) && $transaction->proof_path !== '', 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($transaction->proof_path), 404);

        $mime = in_array($transaction->proof_mime, ['application/pdf', 'image/jpeg', 'image/png'], true)
            ? $transaction->proof_mime
            : 'application/octet-stream';
        $extension = pathinfo($transaction->proof_path, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download(
            $transaction->proof_path,
            'receipt-' . $transaction->reference . '.' . $extension,
            ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']
        );
    }

    public function updateStatus(Request $request, UniversalPaymentTransaction $transaction): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $transaction->branch_id) {
            abort(404);
        }
        $validated = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
        ]);

        if ($transaction->status !== 'pending') {
            abort(422, 'Only pending manual receipts can be reviewed.');
        }

        if ($transaction->gateway !== 'bank_cash') {
            abort(422, 'Online gateway transactions must be updated by the verified provider callback.');
        }

        if ($validated['status'] === 'verified' && trim((string) $transaction->external_trx_id) === '') {
            abort(422, 'A bank receipt or cash voucher reference is required before verification.');
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
        abort_unless(
            $this->plugins->isEnabled('fees-management') && Schema::hasTable('fee_invoices')
                && $this->plugins->isEnabled('payment-management') && Schema::hasTable('payment_transactions'),
            409,
            'Enable Fees and Payment Management before using invoice checkout.'
        );
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'gateway' => ['required', 'in:sslcommerz,card,paypal'],
            'payment_method_id' => ['required_if:gateway,card', 'nullable', 'string', 'max:160'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();
        abort_unless($actor, 401);

        $studentIntegration = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        $invoiceQuery = FeeInvoice::query();
        if ($studentIntegration) { $invoiceQuery->with('student'); }
        $invoice = $invoiceQuery->findOrFail((int) $validated['invoice_id']);
        $isFinanceOperator = $actor->hasRole('admin') || $actor->hasRole('accountant');
        $isInvoiceOwner = $studentIntegration
            ? (int) ($invoice->student?->user_id ?? 0) === (int) $actor->id
            : strtolower((string) $invoice->payer_email) === strtolower((string) $actor->email);
        abort_unless($isFinanceOperator || $isInvoiceOwner, 403, 'You are not authorized to pay this invoice.');

        $enabledRaw = (string) $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,card,paypal,bank_cash');
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

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        if ($actor->branch_id === null || !$this->plugins->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }

        $activeBranch = DB::table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();

        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }

}
