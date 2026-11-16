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

class UniversalPaymentController extends Controller
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
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

        $tx = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-' . strtoupper(Str::random(8)),
            'payer_name' => $validated['payer_name'],
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

        return back()->with('status', "Payment submitted! Reference: {$tx->reference}. Access will be enabled after verification.");
    }
}
