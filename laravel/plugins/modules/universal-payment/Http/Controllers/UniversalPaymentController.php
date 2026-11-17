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

    public function index(): View
    {
        $enabledRaw = $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,bkash,nagad,rocket,card,bank_cash');
        $config = [
            'default_currency' => $this->plugins->getSetting('universal-payment', 'default_currency', (string) config('lms.default_currency', 'BDT')),
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'external_trx_id' => ['nullable', 'string', 'max:120'],
            'storefront_item_id' => ['nullable', 'integer'],
        ]);

        $storefrontItem = null;
        if (!empty($validated['storefront_item_id'])) {
            abort_unless(auth()->check(), 401, 'Sign in before purchasing a digital e-book.');
            abort_unless(Schema::hasTable('storefront_items') && Schema::hasTable('storefront_item_access'), 503, 'E-book sales are not currently available.');
            $storefrontItem = DB::table('storefront_items')->where('id', $validated['storefront_item_id'])
                ->where('type', 'ebook')->where('is_active', true)->first();
            abort_if($storefrontItem === null || (bool) $storefrontItem->is_free || (float) $storefrontItem->price <= 0, 422, 'This is not a paid active e-book.');
            abort_if(empty($storefrontItem->download_url) || !str_starts_with((string) $storefrontItem->download_url, 'private:'), 422, 'The e-book PDF must be privately uploaded before it can be sold.');
        }

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
            'item_title' => $storefrontItem?->title ?? $validated['item_title'],
            'gateway' => $validated['gateway'],
            'currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT') ?: 'BDT',
            'amount' => $storefrontItem ? (float) $storefrontItem->price : $validated['amount'],
            'external_trx_id' => $validated['external_trx_id'] ?? null,
            'user_id' => $storefrontItem ? auth()->id() : null,
            'storefront_item_id' => $storefrontItem?->id,
            'status' => 'pending',
        ]);

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
        if ($transaction->learn_earn_exam_id) {
            return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->withErrors(['payment' => 'Payment was not completed. You can try again.']);
        }
        $slug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
        return redirect('/ebooks/' . rawurlencode((string) $slug))->withErrors(['payment' => 'Payment was not completed. You can try again.']);
    }

    public function sslcommerzCancelled(Request $request): RedirectResponse
    {
        $transaction = $this->storefrontGateway->markSslcommerzFailed($request->all(), 'cancelled');
        if ($transaction->learn_earn_exam_id) {
            return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', 'Payment was cancelled.');
        }
        $slug = DB::table('storefront_items')->where('id', $transaction->storefront_item_id)->value('slug');
        return redirect('/ebooks/' . rawurlencode((string) $slug))->with('status', 'Payment was cancelled.');
    }

}
