<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;

class UniversalPaymentController extends Controller
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
        private readonly StorefrontPaymentGatewayService $storefrontGateway,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $enabledRaw = $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,bkash,nagad,rocket,card,bank_cash');
        $config = [
            'default_currency' => $this->plugins->getSetting('universal-payment', 'default_currency', (string) config('lms.default_currency', 'BDT')),
            'enabled_gateways' => array_filter(array_map('trim', explode(',', (string) $enabledRaw))),
        ];
        $transactionQuery = Schema::hasTable('universal_payment_transactions')
            ? UniversalPaymentTransaction::query()->latest()
            : null;
        if ($transactionQuery && $actor && $actor->hasRole('branch_manager')) {
            $transactionQuery->where('branch_id', $actor->branch_id);
        }
        $transactions = $transactionQuery ? $transactionQuery->limit(50)->get() : collect();

        return view('universal-payment::index', compact('config', 'transactions'));
    }

    public function saveGateways(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'gateways' => ['nullable', 'array'],
            'gateways.*' => ['string', 'in:sslcommerz,bkash,nagad,rocket,card,paypal,bank_cash'],
        ]);

        $values = [
            'default_currency' => strtoupper($validated['default_currency']),
            'enabled_gateways' => implode(',', $validated['gateways'] ?? []),
        ];

        $this->plugins->saveSettings('universal-payment', $values, [], auth()->id());

        return back()->with('status', 'Universal Payment Gateway configuration saved securely.');
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_contact' => ['nullable', 'string', 'max:120'],
            'item_title' => ['required', 'string', 'max:200'],
            'gateway' => ['required', 'in:sslcommerz,bkash,nagad,rocket,card,paypal,bank_cash'],
            'amount' => ['required', 'numeric', 'min:1'],
            'external_trx_id' => ['required', 'string', 'max:120'],
            'proof_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('proof_file')) {
            abort_unless(in_array($validated['gateway'], ['bank_cash', 'offline'], true), 422, 'Receipt evidence is accepted only for manual bank/cash payments.');
            abort_unless(Schema::hasColumn('universal_payment_transactions', 'proof_path')
                && Schema::hasColumn('universal_payment_transactions', 'proof_mime')
                && Schema::hasColumn('universal_payment_transactions', 'proof_size'), 503, 'Receipt evidence storage is not available until the payment migration is applied.');
        }

        $proofFile = $request->file('proof_file');
        $proofPath = $proofFile?->store('universal-payment-proofs', 'local');

        $tx = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-' . strtoupper(Str::random(8)),
            'payer_name' => $validated['payer_name'],
            'payer_contact' => $validated['payer_contact'] ?? null,
            'item_title' => $validated['item_title'],
            'gateway' => $validated['gateway'],
            'currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT') ?: 'BDT',
            'amount' => $validated['amount'],
            'external_trx_id' => trim($validated['external_trx_id']),
            'branch_id' => $actor?->branch_id,
            'status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
            'proof_path' => $proofPath,
            'proof_mime' => $proofFile?->getMimeType(),
            'proof_size' => $proofFile?->getSize(),
        ]);

        $this->audit->log('universal_payment.recorded', 'universal_payment', $tx->reference, ['amount' => $tx->amount, 'gateway' => $tx->gateway]);

        return back()->with('status', "Transaction '{$tx->reference}' recorded ({$tx->gateway} · {$tx->status}).");
    }

    public function downloadProof(Request $request, UniversalPaymentTransaction $transaction): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $this->ensureAssignedBranch($actor);
        if ($actor->hasRole('branch_manager')) {
            abort_unless((int) ($actor->branch_id ?? 0) > 0
                && (int) ($transaction->branch_id ?? 0) === (int) $actor->branch_id, 404);
        }

        abort_unless(is_string($transaction->proof_path) && $transaction->proof_path !== '', 404);
        abort_unless(Storage::disk('local')->exists($transaction->proof_path), 404);

        $filename = basename($transaction->proof_path);
        $mime = in_array($transaction->proof_mime, ['application/pdf', 'image/jpeg', 'image/png'], true)
            ? $transaction->proof_mime
            : 'application/octet-stream';

        return Storage::disk('local')->download($transaction->proof_path, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function updateStatus(Request $request, UniversalPaymentTransaction $transaction): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless((int) ($actor->branch_id ?? 0) > 0
                && (int) ($transaction->branch_id ?? 0) === (int) $actor->branch_id, 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'payment_reference' => ['required', 'string', 'max:120'],
        ]);

        abort_unless($transaction->status === 'pending'
            && in_array($transaction->gateway, ['bank_cash', 'offline'], true),
            422,
            'Only pending manual receipts can be reviewed.'
        );

        $reference = trim($validated['payment_reference']);
        abort_if($reference === '', 422, 'A bank receipt or cash voucher reference is required before verification.');

        $transaction->update([
            'external_trx_id' => $reference,
            'status' => $validated['status'],
            'verified_by' => $validated['status'] === 'verified' ? $actor?->id : null,
            'verified_at' => $validated['status'] === 'verified' ? now() : null,
        ]);

        // Online transactions are changed only by a verified provider callback, never by manual review.

        if ($transaction->storefront_item_id && $transaction->user_id && Schema::hasTable('storefront_item_access')) {
            if ($validated['status'] === 'verified') {
                DB::table('storefront_item_access')->updateOrInsert(
                    ['storefront_item_id' => $transaction->storefront_item_id, 'user_id' => $transaction->user_id],
                    ['payment_transaction_id' => $transaction->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]
                );
            } else {
                DB::table('storefront_item_access')->where('storefront_item_id', $transaction->storefront_item_id)
                    ->where('user_id', $transaction->user_id)->delete();
            }
        }

        $this->audit->log('universal_payment.status_updated', 'universal_payment', $transaction->reference, ['status' => $validated['status']]);

        return back()->with('status', "Transaction '{$transaction->reference}' marked {$validated['status']}.");
    }

    public function publicCheckout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_contact' => ['required', 'string', 'max:120'],
            'item_title' => ['required', 'string', 'max:200'],
            'gateway' => ['required', 'in:sslcommerz,bkash,nagad,rocket,card,paypal,bank_cash'],
            'external_trx_id' => ['nullable', 'string', 'max:120'],
            'storefront_item_id' => ['nullable', 'integer'],
            'fee_invoice_id' => ['nullable', 'integer', 'exists:fee_invoices,id'],
        ]);

        $invoice = null;
        if (!empty($validated['fee_invoice_id'])) {
            abort_unless(auth()->check() && Schema::hasTable('fee_invoices'), 401, 'Sign in to pay an invoice.');
            $invoice = DB::table('fee_invoices')->where('id', $validated['fee_invoice_id'])->first();
            $isInvoiceOwner = $invoice !== null
                && filled($invoice->payer_email)
                && hash_equals(strtolower((string) $invoice->payer_email), strtolower((string) auth()->user()->email));
            abort_unless($isInvoiceOwner, 403, 'You can only pay an invoice issued to your account.');
            $invoiceBalance = max(0, (float) $invoice->net_amount - (float) $invoice->paid_amount);
            abort_if($invoiceBalance <= 0, 422, 'This invoice has no outstanding balance.');
        }

        $storefrontItem = null;
        if (!empty($validated['storefront_item_id'])) {
            abort_unless(auth()->check(), 401, 'Sign in before purchasing a digital e-book.');
            abort_unless(Schema::hasTable('storefront_items') && Schema::hasTable('storefront_item_access'), 503, 'E-book sales are not currently available.');
            $storefrontItem = DB::table('storefront_items')->where('id', $validated['storefront_item_id'])
                ->where('type', 'ebook')->where('is_active', true)->first();
            abort_if($storefrontItem === null || (bool) $storefrontItem->is_free || (float) $storefrontItem->price <= 0, 422, 'This is not a paid active e-book.');
            abort_if(empty($storefrontItem->download_url) || !str_starts_with((string) $storefrontItem->download_url, 'private:'), 422, 'The e-book PDF must be privately uploaded before it can be sold.');
        }

        abort_unless($invoice !== null || $storefrontItem !== null, 422, 'Choose an invoice or paid e-book to continue checkout.');

        if ($storefrontItem !== null && $validated['gateway'] !== 'sslcommerz') {
            return back()->withErrors(['gateway' => 'Choose SSLCOMMERZ for secure automatic e-book checkout.'])->withInput();
        }

        if ($storefrontItem !== null && DB::table('storefront_item_access')
            ->where('storefront_item_id', $storefrontItem->id)->where('user_id', auth()->id())->exists()) {
            return redirect('/ebooks/' . rawurlencode((string) $storefrontItem->slug))->with('status', 'You already have access to this e-book.');
        }

        if ($storefrontItem !== null) {
            $pendingCheckout = UniversalPaymentTransaction::query()
                ->where('user_id', auth()->id())
                ->where('storefront_item_id', $storefrontItem->id)
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->exists();
            abort_if($pendingCheckout, 409, 'A checkout for this e-book is already pending. Wait for its result before retrying.');
        }

        $tx = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-' . strtoupper(Str::random(8)),
            'payer_name' => $storefrontItem ? auth()->user()->name : $validated['payer_name'],
            'payer_contact' => $validated['payer_contact'],
            'item_title' => $invoice ? ('Invoice ' . $invoice->invoice_number) : ($storefrontItem?->title ?? $validated['item_title']),
            'gateway' => $validated['gateway'],
            'currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT') ?: 'BDT',
            'amount' => $invoice ? $invoiceBalance : (float) $storefrontItem->price,
            'external_trx_id' => $validated['external_trx_id'] ?? null,
            'user_id' => auth()->id(),
            'fee_invoice_id' => $invoice?->id,
            'storefront_item_id' => $storefrontItem?->id,
            'status' => 'pending',
        ]);

        if ($invoice !== null) {
            return back()->with('status', "Invoice payment submitted! Reference: {$tx->reference}. Access will be updated after payment verification.");
        }

        if ($storefrontItem !== null) {
            try {
                $checkoutUrl = $this->storefrontGateway->initiateSslcommerz($tx, $storefrontItem, auth()->user(), $validated['payer_contact']);
                return redirect()->away($checkoutUrl);
            } catch (\Throwable $exception) {
                report($exception);
                return back()->withErrors(['gateway' => 'Unable to start hosted checkout. Check the SSLCOMMERZ plugin configuration and try again.'])->withInput();
            }
        }

        return back()->with('status', "Payment submitted! Reference: {$tx->reference}. Access will be enabled after verification.");
    }
    public function sslcommerzSuccess(Request $request): RedirectResponse
    {
        try {
            $transaction = $this->storefrontGateway->verifySslcommerz($request->all());
            if ($transaction->status === 'risk_review') {
                if ($transaction->learn_earn_exam_id) {
                    return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)
                        ->with('status', 'Payment was received but requires manual review. No additional exam access was granted.');
                }
                $reviewSlug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
                return $reviewSlug
                    ? redirect('/ebooks/' . rawurlencode((string) $reviewSlug))->with('status', 'Payment was received but requires manual review. Existing access was not changed.')
                    : redirect('/ebooks')->with('status', 'Payment was received but requires manual review.');
            }
            if ($transaction->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)
                    ->with('status', 'Payment verified. Your paid exam is now unlocked.');
            }
            $slug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
            return redirect('/ebooks/' . rawurlencode((string) $slug))->with('status', 'Payment verified. Your e-book download is now available.');
        } catch (\Throwable $exception) {
            report($exception);
            $reference = trim((string) $request->input('tran_id', ''));
            $transaction = UniversalPaymentTransaction::query()->where('reference', $reference)->first();
            if ($transaction?->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)
                    ->withErrors(['payment' => 'Payment could not yet be verified. No exam access has been granted.']);
            }
            $slug = $transaction?->storefront_item_id
                ? DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug')
                : null;
            return $slug
                ? redirect('/ebooks/' . rawurlencode((string) $slug))->withErrors(['payment' => 'Payment could not yet be verified. No download access has been granted.'])
                : redirect('/ebooks')->withErrors(['payment' => 'Payment could not yet be verified.']);
        }
    }

    public function sslcommerzIpn(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $transaction = $this->storefrontGateway->verifySslcommerz($request->all());
            return response()->json(['status' => $transaction->status, 'reference' => $transaction->reference]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['status' => 'pending_validation'], 422);
        }
    }

    public function sslcommerzFailed(Request $request): RedirectResponse
    {
        $transaction = $this->storefrontGateway->markSslcommerzFailed($request->all(), 'failed');
        if (in_array($transaction->status, ['verified', 'risk_review'], true)) {
            $message = $transaction->status === 'verified'
                ? 'Payment was already verified; your access remains available.'
                : 'Payment was received but remains under manual review; no additional access was granted.';
            if ($transaction->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', $message);
            }
            $reviewSlug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
            return $reviewSlug
                ? redirect('/ebooks/' . rawurlencode((string) $reviewSlug))->with('status', $message)
                : redirect('/ebooks')->with('status', $message);
        }
        if ($transaction->learn_earn_exam_id) {
            return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->withErrors(['payment' => 'Payment was not completed. You can try again.']);
        }
        $slug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
        return redirect('/ebooks/' . rawurlencode((string) $slug))->withErrors(['payment' => 'Payment was not completed. You can try again.']);
    }

    public function sslcommerzCancelled(Request $request): RedirectResponse
    {
        $transaction = $this->storefrontGateway->markSslcommerzFailed($request->all(), 'cancelled');
        if (in_array($transaction->status, ['verified', 'risk_review'], true)) {
            $message = $transaction->status === 'verified'
                ? 'Payment was already verified; your access remains available.'
                : 'Payment was received but remains under manual review; no additional access was granted.';
            if ($transaction->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', $message);
            }
            $reviewSlug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
            return $reviewSlug
                ? redirect('/ebooks/' . rawurlencode((string) $reviewSlug))->with('status', $message)
                : redirect('/ebooks')->with('status', $message);
        }
        if ($transaction->learn_earn_exam_id) {
            return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', 'Payment was cancelled.');
        }
        $slug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
        return redirect('/ebooks/' . rawurlencode((string) $slug))->with('status', 'Payment was cancelled.');
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !$this->plugins->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
        $activeBranch = DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
