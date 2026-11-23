<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;

class UniversalPaymentController extends Controller
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
        private readonly StorefrontPaymentGatewayService $storefrontGateway,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $enabledRaw = $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,stripe,paypal,bkash,nagad,rocket,card,cash,bank_transfer,bank_cash,offline,cheque');
        $config = [
            'default_currency' => $this->plugins->getSetting('universal-payment', 'default_currency', (string) config('lms.default_currency', 'BDT')),
            'enabled_gateways' => array_filter(array_map('trim', explode(',', (string) $enabledRaw))),
            'allow_offline_payments' => (string) $this->plugins->getSetting('universal-payment', 'allow_offline_payments', '1') === '1',
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
        abort_unless(!$request->user()?->hasRole('branch_manager'), 403, 'Branch managers cannot change global payment gateway settings.');

        $validated = $request->validate([
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'gateways' => ['nullable', 'array'],
            'gateways.*' => ['string', 'in:sslcommerz,stripe,paypal,bkash,nagad,rocket,card,cash,bank_transfer,bank_cash,offline,cheque'],
            'allow_offline_payments' => ['nullable', 'boolean'],
        ]);

        $values = [
            'default_currency' => strtoupper($validated['default_currency']),
            'enabled_gateways' => implode(',', $validated['gateways'] ?? []),
            'allow_offline_payments' => $request->boolean('allow_offline_payments') ? '1' : '0',
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
            'gateway' => ['required', 'in:sslcommerz,stripe,paypal,bkash,nagad,rocket,card,cash,bank_transfer,bank_cash,offline,cheque'],
            'amount' => ['required', 'numeric', 'min:1'],
            'external_trx_id' => ['required', 'string', 'max:120'],
            'proof_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $this->assertGatewayEnabled($validated['gateway']);

        if ($request->hasFile('proof_file')) {
            abort_unless(in_array($validated['gateway'], ['cash', 'bank_transfer', 'bank_cash', 'offline', 'cheque', 'bkash', 'nagad', 'rocket'], true), 422, 'Receipt evidence is accepted only for manual wallet, bank or cash payments.');
            abort_unless(Schema::hasColumn('universal_payment_transactions', 'proof_path')
                && Schema::hasColumn('universal_payment_transactions', 'proof_mime')
                && Schema::hasColumn('universal_payment_transactions', 'proof_size'), 503, 'Receipt evidence storage is not available until the payment migration is applied.');
        }

        $proofFile = $request->file('proof_file');
        $proofPath = $proofFile?->store('universal-payment-proofs', 'local');

        try {
            $tx = DB::transaction(function () use ($validated, $actor, $proofPath, $proofFile): UniversalPaymentTransaction {
                $transaction = UniversalPaymentTransaction::query()->create([
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

                app(\Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry::class)
                    ->claim($transaction->gateway, (string) $transaction->external_trx_id, (int) $transaction->id);

                return $transaction;
            });
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('local')->delete($proofPath);
            }
            throw $exception;
        }

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

        $reference = trim($validated['payment_reference']);
        abort_if($reference === '', 422, 'A bank receipt or cash voucher reference is required before verification.');

        $transaction = DB::transaction(function () use ($transaction, $validated, $reference, $actor): UniversalPaymentTransaction {
            $lockedTransaction = UniversalPaymentTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor && $actor->hasRole('branch_manager')) {
                abort_unless((int) ($actor->branch_id ?? 0) > 0
                    && (int) ($lockedTransaction->branch_id ?? 0) === (int) $actor->branch_id, 404);
            }

            abort_unless($lockedTransaction->status === 'pending'
                && in_array($lockedTransaction->gateway, ['cash', 'bank_transfer', 'bank_cash', 'offline', 'cheque', 'bkash', 'nagad', 'rocket'], true),
                422,
                'Only pending manual receipts can be reviewed.'
            );

            // A fee-invoice receipt is settled atomically with its invoice ledger.
            // Only the manual bank/cash workflow may update an invoice here; online
            // gateways must use their own verified provider callback adapters.
            if ($lockedTransaction->fee_invoice_id !== null) {
                abort_unless(
                    Schema::hasTable('fee_invoices')
                        && Schema::hasColumn('fee_invoices', 'paid_amount')
                        && Schema::hasColumn('fee_invoices', 'net_amount')
                        && Schema::hasColumn('fee_invoices', 'status'),
                    503,
                    'Fee invoice storage is unavailable; the payment was not changed.'
                );

                $invoice = DB::table('fee_invoices')
                    ->where('id', $lockedTransaction->fee_invoice_id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($invoice !== null, 404, 'The linked fee invoice no longer exists.');

                if ($actor && $actor->hasRole('branch_manager')) {
                    abort_unless((int) ($invoice->branch_id ?? 0) === (int) $actor->branch_id, 404);
                }

                if ($validated['status'] === 'verified') {
                    $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
                    $amount = round((float) $lockedTransaction->amount, 2);
                    abort_if($remaining <= 0 || $amount > $remaining + 0.01, 422, 'This receipt exceeds the remaining invoice balance.');

                    $hasCommerceOrder = Schema::hasTable('commerce_orders')
                        && DB::table('commerce_orders')->where('invoice_id', $invoice->id)->exists();

                    if ($this->plugins->isEnabled('payment-management') && Schema::hasTable('payment_transactions')) {
                        // Use the canonical payment settlement pipeline so manual receipts
                        // grant the same commerce entitlements, redeem coupons, and record
                        // referral income as verified online gateway transactions.
                        $payment = PaymentTransaction::query()->create([
                            'transaction_ref' => 'UPAY-' . strtoupper(Str::random(20)),
                            'invoice_id' => $invoice->id,
                            'branch_id' => $invoice->branch_id ?? null,
                            'gateway_slug' => 'offline',
                            'amount' => $amount,
                            'currency' => (string) ($invoice->currency ?? 'BDT'),
                            'status' => 'initiated',
                            'metadata' => [
                                'source' => 'universal-payment',
                                'universal_payment_transaction_id' => (int) $lockedTransaction->id,
                                'submitted_external_trx_id' => (string) ($lockedTransaction->external_trx_id ?? ''),
                            ],
                        ]);

                        app(PaymentSettlementService::class)->settleTransaction(
                            $payment,
                            $reference,
                            ['source' => 'universal-payment', 'universal_payment_transaction_id' => (int) $lockedTransaction->id],
                            $actor?->id
                        );
                    } else {
                        abort_unless(
                            !$hasCommerceOrder,
                            503,
                            'Payment Management must be enabled to fulfill commerce orders and grant their entitlements.'
                        );

                        $newPaid = round((float) $invoice->paid_amount + $amount, 2);
                        $invoiceFullyPaid = $newPaid + 0.01 >= (float) $invoice->net_amount;
                        if ($invoiceFullyPaid) {
                            $this->assertInvoiceEnrollmentBranchAccess($invoice);
                        }

                        DB::table('fee_invoices')->where('id', $invoice->id)->update([
                            'paid_amount' => $newPaid,
                            'status' => $invoiceFullyPaid ? 'paid' : 'partial',
                            'updated_at' => now(),
                        ]);

                        if ($invoiceFullyPaid
                            && Schema::hasTable('enrollments')
                            && Schema::hasColumn('enrollments', 'invoice_id')) {
                            DB::table('enrollments')
                                ->where('invoice_id', $invoice->id)
                                ->where('status', 'pending')
                                ->update(['status' => 'active', 'updated_at' => now()]);
                        }
                    }
                } else {
                    // A rejected manual receipt must release the unpaid order so
                    // the student can retry. Detach gift drafts before failing the
                    // order, otherwise they remain bound to an unpayable order.
                    if (Schema::hasTable('commerce_orders') && Schema::hasTable('commerce_order_items')) {
                        $orders = DB::table('commerce_orders')
                            ->where('invoice_id', $invoice->id)
                            ->where('status', 'awaiting_payment')
                            ->lockForUpdate()
                            ->get();
                        foreach ($orders as $order) {
                            $items = DB::table('commerce_order_items')->where('order_id', $order->id)->get();
                            foreach ($items as $item) {
                                if ($item->item_type === 'gift_course' && Schema::hasTable('course_gifts')) {
                                    DB::table('course_gifts')->where('id', $item->item_id)
                                        ->where('commerce_order_item_id', $item->id)
                                        ->where('status', 'awaiting_payment')
                                        ->whereNull('invoice_id')
                                        ->whereNull('payment_transaction_id')
                                        ->update(['commerce_order_item_id' => null, 'updated_at' => now()]);
                                } elseif ($item->item_type === 'gift_voucher' && Schema::hasTable('course_gift_vouchers')) {
                                    DB::table('course_gift_vouchers')->where('id', $item->item_id)
                                        ->where('commerce_order_item_id', $item->id)
                                        ->where('status', 'awaiting_payment')
                                        ->whereNull('invoice_id')
                                        ->whereNull('payment_transaction_id')
                                        ->update(['commerce_order_item_id' => null, 'updated_at' => now()]);
                                }
                            }

                            if (Schema::hasTable('promo_coupon_redemptions') && filled($order->coupon_reservation_key ?? null)) {
                                DB::table('promo_coupon_redemptions')
                                    ->where('reservation_key', $order->coupon_reservation_key)
                                    ->where('status', 'reserved')
                                    ->update(['status' => 'released', 'updated_at' => now()]);
                            }

                            DB::table('commerce_orders')->where('id', $order->id)->update([
                                'status' => 'failed',
                                'payment_status' => 'failed',
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            if ($validated['status'] === 'verified') {
                app(\Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry::class)
                    ->claim($lockedTransaction->gateway, $reference, (int) $lockedTransaction->id);
            }

            $lockedTransaction->update([
                'external_trx_id' => $reference,
                'status' => $validated['status'],
                'verified_by' => $validated['status'] === 'verified' ? $actor?->id : null,
                'verified_at' => $validated['status'] === 'verified' ? now() : null,
            ]);

            // Online transactions are changed only by a verified provider callback, never by manual review.
            if ($lockedTransaction->storefront_item_id && $lockedTransaction->user_id && Schema::hasTable('storefront_item_access')) {
                if ($validated['status'] === 'verified') {
                    DB::table('storefront_item_access')->updateOrInsert(
                        ['storefront_item_id' => $lockedTransaction->storefront_item_id, 'user_id' => $lockedTransaction->user_id],
                        ['payment_transaction_id' => $lockedTransaction->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]
                    );
                } else {
                    DB::table('storefront_item_access')->where('storefront_item_id', $lockedTransaction->storefront_item_id)
                        ->where('user_id', $lockedTransaction->user_id)->delete();
                }
            }

            return $lockedTransaction->fresh();
        });

        $this->audit->log('universal_payment.status_updated', 'universal_payment', $transaction->reference, ['status' => $validated['status']]);

        return back()->with('status', "Transaction '{$transaction->reference}' marked {$validated['status']}.");
    }

    public function publicCheckout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'payer_contact' => ['required', 'string', 'max:120'],
            'item_title' => ['required', 'string', 'max:200'],
            'gateway' => ['required', 'in:sslcommerz,stripe,paypal,bkash,nagad,rocket,card,cash,bank_transfer,bank_cash,offline,cheque'],
            'external_trx_id' => ['nullable', 'string', 'max:120'],
            'storefront_item_id' => ['nullable', 'integer'],
            'fee_invoice_id' => ['nullable', 'integer'],
        ]);

        $this->assertGatewayEnabled($validated['gateway']);

        $hasInvoice = !empty($validated['fee_invoice_id']);
        $hasStorefrontItem = !empty($validated['storefront_item_id']);
        abort_unless(
            $hasInvoice !== $hasStorefrontItem,
            422,
            'Choose exactly one fee invoice or paid e-book to pay.'
        );

        $invoice = null;
        if ($hasInvoice) {
            abort_unless(auth()->check(), 401, 'Sign in to pay an invoice.');
            abort_unless(Schema::hasTable('fee_invoices'), 503, 'Fee invoice payments are not currently available.');
            $invoice = DB::table('fee_invoices')->where('id', $validated['fee_invoice_id'])->first();
            $isInvoiceOwner = $invoice !== null
                && filled($invoice->payer_email)
                && hash_equals(strtolower((string) $invoice->payer_email), strtolower((string) auth()->user()->email));
            abort_unless($isInvoiceOwner, 403, 'You can only pay an invoice issued to your account.');
            $manualInvoiceMethods = ['cash', 'bank_transfer', 'bank_cash', 'offline', 'cheque', 'nagad', 'rocket'];
            $onlineInvoiceMethods = ['sslcommerz', 'bkash', 'card'];
            abort_unless(
                in_array($validated['gateway'], $manualInvoiceMethods, true)
                    || in_array($validated['gateway'], $onlineInvoiceMethods, true),
                422,
                'Choose a supported manual receipt method or a configured online gateway.'
            );
            if (in_array($validated['gateway'], $manualInvoiceMethods, true)) {
                abort_if(trim((string) ($validated['external_trx_id'] ?? '')) === '', 422, 'Enter the bank transaction ID or cash receipt reference.');
            }
            if ($validated['gateway'] === 'card') {
                $this->assertGatewayEnabled('sslcommerz');
            }
            abort_if(
                UniversalPaymentTransaction::query()
                    ->where('fee_invoice_id', $invoice->id)
                    ->where('status', 'pending')
                    ->exists(),
                409,
                'A payment receipt for this invoice is already awaiting verification.'
            );
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
        abort_if($invoice !== null && $storefrontItem !== null, 422, 'Choose one checkout item at a time.');

        $manualMethods = ['cash', 'bank_transfer', 'bank_cash', 'offline', 'cheque'];
        if ($storefrontItem !== null && in_array($validated['gateway'], $manualMethods, true)) {
            return back()->withErrors(['gateway' => 'Manual payment methods are available for invoice/receipt submission; e-book checkout requires a verified online gateway.'])->withInput();
        }

        if ($storefrontItem !== null && DB::table('storefront_item_access')
            ->where('storefront_item_id', $storefrontItem->id)->where('user_id', auth()->id())->exists()) {
            return redirect('/ebooks/' . rawurlencode((string) $storefrontItem->slug))->with('status', 'You already have access to this e-book.');
        }

        if ($invoice !== null) {
            $tx = DB::transaction(function () use ($invoice, $validated): UniversalPaymentTransaction {
                $lockedInvoice = DB::table('fee_invoices')->where('id', $invoice->id)->lockForUpdate()->first();
                abort_unless($lockedInvoice !== null, 404, 'The fee invoice no longer exists.');
                $isStillOwner = filled($lockedInvoice->payer_email)
                    && hash_equals(strtolower((string) $lockedInvoice->payer_email), strtolower((string) auth()->user()->email));
                abort_unless($isStillOwner, 403, 'You can only pay an invoice issued to your account.');

                abort_if(
                    UniversalPaymentTransaction::query()
                        ->where('fee_invoice_id', $lockedInvoice->id)
                        ->where('status', 'pending')
                        ->exists(),
                    409,
                    'A payment receipt for this invoice is already awaiting verification.'
                );

                $balance = round((float) $lockedInvoice->net_amount - (float) $lockedInvoice->paid_amount, 2);
                abort_if($balance <= 0, 422, 'This invoice has no outstanding balance.');

                $transaction = UniversalPaymentTransaction::query()->create([
                    'reference' => 'UPAY-' . strtoupper(Str::random(8)),
                    'payer_name' => $validated['payer_name'],
                    'payer_contact' => $validated['payer_contact'],
                    'item_title' => 'Invoice ' . $lockedInvoice->invoice_number,
                    'gateway' => $validated['gateway'] === 'card' ? 'sslcommerz' : $validated['gateway'],
                    'currency' => (string) ($lockedInvoice->currency ?: 'BDT'),
                    'amount' => $balance,
                    'external_trx_id' => in_array($validated['gateway'], ['sslcommerz', 'bkash', 'card'], true)
                        ? null
                        : trim((string) $validated['external_trx_id']),
                    'user_id' => auth()->id(),
                    'branch_id' => $lockedInvoice->branch_id ?? null,
                    'fee_invoice_id' => $lockedInvoice->id,
                    'status' => 'pending',
                ]);

                app(\Plugins\Modules\UniversalPayment\Services\ExternalTransactionReferenceRegistry::class)
                    ->claim((string) $transaction->gateway, (string) $transaction->external_trx_id, (int) $transaction->id);

                return $transaction;
            });
        } else {
            // Serialize checkout creation on the storefront item row. A preflight
            // pending-check alone can race when the same buyer submits twice.
            $tx = DB::transaction(function () use ($storefrontItem, $validated): UniversalPaymentTransaction {
                $lockedItem = DB::table('storefront_items')
                    ->where('id', $storefrontItem->id)
                    ->where('type', 'ebook')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $lockedItem !== null
                        && !(bool) $lockedItem->is_free
                        && (float) $lockedItem->price > 0,
                    409,
                    'This e-book is no longer available for purchase.'
                );
                abort_if(
                    abs((float) $lockedItem->price - (float) $storefrontItem->price) > 0.01,
                    409,
                    'The e-book price changed. Reload the page before starting checkout.'
                );
                abort_if(
                    empty($lockedItem->download_url)
                        || !str_starts_with((string) $lockedItem->download_url, 'private:'),
                    422,
                    'The e-book PDF must be privately uploaded before it can be sold.'
                );
                abort_if(
                    DB::table('storefront_item_access')
                        ->where('storefront_item_id', $lockedItem->id)
                        ->where('user_id', auth()->id())
                        ->exists(),
                    409,
                    'You already have access to this e-book.'
                );

                $pendingCheckout = UniversalPaymentTransaction::query()
                    ->where('user_id', auth()->id())
                    ->where('storefront_item_id', $lockedItem->id)
                    ->whereIn('status', ['pending', 'risk_review'])
                    ->exists();
                abort_if($pendingCheckout, 409, 'A checkout for this e-book is still pending or requires provider reconciliation. Contact support before retrying.');

                return UniversalPaymentTransaction::query()->create([
                    'reference' => 'UPAY-' . strtoupper(Str::random(8)),
                    'payer_name' => auth()->user()->name,
                    'payer_contact' => $validated['payer_contact'],
                    'item_title' => $lockedItem->title,
                    'gateway' => $validated['gateway'] === 'card' ? 'sslcommerz' : $validated['gateway'],
                    'currency' => $this->plugins->getSetting('universal-payment', 'default_currency', 'BDT') ?: 'BDT',
                    'amount' => (float) $lockedItem->price,
                    'external_trx_id' => null,
                    'user_id' => auth()->id(),
                    'storefront_item_id' => $lockedItem->id,
                    'status' => 'pending',
                ]);
            });
        }

        if ($invoice !== null && in_array($validated['gateway'], ['sslcommerz', 'bkash', 'card'], true)) {
            try {
                $gatewaySlug = $validated['gateway'] === 'card' ? 'gateway-sslcommerz' : 'gateway-' . $validated['gateway'];
                abort_unless($this->gatewayRegistry->supportsStorefront($gatewaySlug), 422, 'The selected gateway has no verified invoice checkout adapter yet.');
                $checkout = $this->gatewayRegistry->initiate($gatewaySlug, [
                    'universal_transaction' => $tx,
                    'storefront_item' => (object) ['title' => $tx->item_title],
                    'user' => auth()->user(),
                    'payer_contact' => $validated['payer_contact'],
                    'context' => ['purpose' => 'fee_invoice'],
                ]);
                $checkoutUrl = (string) ($checkout['redirect_url'] ?? '');
                abort_unless($checkoutUrl !== '' && strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) === 'https', 502, 'The selected gateway did not return a secure checkout URL.');
                return redirect()->away($checkoutUrl);
            } catch (\\Throwable $exception) {
                report($exception);
                $ambiguous = $exception instanceof \\Illuminate\\Http\\Client\\ConnectionException;
                DB::transaction(function () use ($tx, $ambiguous): void {
                    $locked = UniversalPaymentTransaction::query()->whereKey($tx->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === 'pending') {
                        $locked->update([
                            'status' => $ambiguous ? 'risk_review' : 'failed',
                            'notes' => $ambiguous
                                ? 'Online invoice checkout initialization outcome is unknown; reconcile with the provider before retrying.'
                                : 'Online invoice checkout could not be initialized.',
                        ]);
                    }
                });
                return back()->withErrors(['payment' => $ambiguous
                    ? 'The payment session outcome is unknown. Contact support before retrying.'
                    : 'Unable to start online invoice checkout. Check gateway configuration and try again.'])->withInput();
            }
        }

        if ($invoice !== null) {
            return back()->with('status', "Invoice payment submitted! Reference: {$tx->reference}. Access will be updated after payment verification.");
        }

        if ($storefrontItem !== null) {
            try {
                $gatewaySlug = $validated['gateway'] === 'card' ? 'gateway-sslcommerz' : 'gateway-' . str_replace('_', '-', $validated['gateway']);
                if ($validated['gateway'] === 'card') {
                    $this->assertGatewayEnabled('sslcommerz');
                }
                $this->assertGatewayEnabled($validated['gateway']);
                abort_unless($this->gatewayRegistry->supportsStorefront($gatewaySlug), 422, 'The selected gateway has no verified e-book storefront adapter yet.');
                $checkout = $this->gatewayRegistry->initiate($gatewaySlug, [
                    'universal_transaction' => $tx,
                    'storefront_item' => $storefrontItem,
                    'user' => auth()->user(),
                    'payer_contact' => $validated['payer_contact'],
                    'context' => ['purpose' => 'ebook'],
                ]);
                $checkoutUrl = (string) ($checkout['redirect_url'] ?? '');
                abort_unless($checkoutUrl !== '' && strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) === 'https', 502, 'The selected payment gateway did not return a secure checkout URL.');
                return redirect()->away($checkoutUrl);
            } catch (\Throwable $exception) {
                report($exception);
                $ambiguous = $exception instanceof \Illuminate\Http\Client\ConnectionException;
                DB::transaction(function () use ($tx, $ambiguous): void {
                    $locked = UniversalPaymentTransaction::query()->whereKey($tx->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === 'pending') {
                        $locked->update([
                            'status' => $ambiguous ? 'risk_review' : 'failed',
                            'notes' => $ambiguous
                                ? 'SSLCOMMERZ session initialization outcome is unknown. Reconcile with the provider before allowing a retry.'
                                : 'SSLCOMMERZ checkout could not be initialized before a provider session was confirmed.',
                        ]);
                    }
                });
                $message = $ambiguous
                    ? 'The payment session outcome is unknown. Contact support before retrying; do not submit another payment.'
                    : 'Unable to start hosted checkout. Check the SSLCOMMERZ plugin configuration and try again.';
                return back()->withErrors(['gateway' => $message])->withInput();
            }
        }

        return back()->with('status', "Payment submitted! Reference: {$tx->reference}. Access will be enabled after verification.");
    }
    public function bkashCallback(Request $request): RedirectResponse
    {
        try {
            $transaction = app(\Plugins\Modules\GatewayBkash\Services\BkashGatewayService::class)
                ->verifyUniversalCallback((string) $request->query('paymentID', ''));
            $slug = $this->storefrontSlugFor($transaction);
            return $slug
                ? redirect('/ebooks/' . rawurlencode($slug))->with('status', 'bKash payment verified. Your e-book is now available.')
                : redirect('/ebooks')->with('status', 'bKash payment verified. Contact support if your purchase is not visible.');
        } catch (\Throwable $exception) {
            report($exception);
            return redirect('/ebooks')->withErrors(['payment' => 'bKash payment could not yet be verified. No download access has been granted; contact support before retrying.']);
        }
    }

    public function sslcommerzSuccess(Request $request): RedirectResponse
    {
        try {
            $transaction = $this->storefrontGateway->verifySslcommerz($request->all());
            $checkoutNotes = json_decode((string) $transaction->notes, true);
            if (is_array($checkoutNotes) && in_array($checkoutNotes['purpose'] ?? null, ['course_cart', 'course_gift', 'course_gift_voucher'], true)) {
                $message = match ($checkoutNotes['purpose']) {
                    'course_cart' => $transaction->status === 'risk_review'
                        ? 'Payment requires manual review. Course access has not been granted yet.'
                        : 'Payment verified. Your purchased courses are now active.',
                    'course_gift' => $transaction->status === 'risk_review'
                        ? 'Gift payment requires manual review. The recipient cannot redeem it yet.'
                        : 'Gift payment verified. The gift is ready for the recipient email address.',
                    default => $transaction->status === 'risk_review'
                        ? 'Voucher payment requires manual review. The recipient cannot claim it yet.'
                        : 'Gift voucher payment verified. The voucher has been sent to the recipient email address.',
                };
                return redirect('/student/course-commerce/cart')->with('status', $message);
            }
            if ($transaction->status === 'risk_review') {
                if ($transaction->learn_earn_exam_id) {
                    return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)
                        ->with('status', 'Payment was received but requires manual review. No additional exam access was granted.');
                }
                $reviewSlug = $this->storefrontSlugFor($transaction);
                return $reviewSlug
                    ? redirect('/ebooks/' . rawurlencode((string) $reviewSlug))->with('status', 'Payment was received but requires manual review. Existing access was not changed.')
                    : redirect('/ebooks')->with('status', 'Payment was received but requires manual review.');
            }
            if ($transaction->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)
                    ->with('status', 'Payment verified. Your paid exam is now unlocked.');
            }
            $slug = $this->storefrontSlugFor($transaction);
            return $slug
                ? redirect('/ebooks/' . rawurlencode($slug))->with('status', 'Payment verified. Your e-book download is now available.')
                : redirect('/ebooks')->with('status', 'Payment was verified, but the storefront item is no longer available. Contact support if you need help.');
        } catch (\Throwable $exception) {
            report($exception);
            $reference = trim((string) $request->input('tran_id', ''));
            $transaction = UniversalPaymentTransaction::query()->where('reference', $reference)->first();
            $checkoutNotes = json_decode((string) ($transaction?->notes ?? ''), true);
            if (is_array($checkoutNotes) && in_array($checkoutNotes['purpose'] ?? null, ['course_cart', 'course_gift', 'course_gift_voucher'], true)) {
                return redirect('/student/course-commerce/cart')->withErrors(['payment' =>
                    'Payment could not yet be verified. No course access, gift redemption or voucher credit has been issued.']);
            }
            if ($transaction?->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)
                    ->withErrors(['payment' => 'Payment could not yet be verified. No exam access has been granted.']);
            }
            $slug = $transaction?->storefront_item_id
                ? $this->storefrontSlugFor($transaction)
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
        $checkoutNotes = json_decode((string) $transaction->notes, true);
        if (is_array($checkoutNotes) && in_array($checkoutNotes['purpose'] ?? null, ['course_cart', 'course_gift', 'course_gift_voucher'], true)) {
            if (in_array($transaction->status, ['verified', 'risk_review'], true)) {
                return redirect('/student/course-commerce/cart')->with('status', $transaction->status === 'verified'
                    ? 'Payment was already verified; your course or gift access remains available.'
                    : 'Payment requires manual review. No course or gift access has been granted yet.');
            }
            return redirect('/student/course-commerce/cart')->withErrors(['payment' => 'The provider return did not verify the payment. The transaction remains pending; do not retry until support reconciles its status.']);
        }
        if (in_array($transaction->status, ['verified', 'risk_review'], true)) {
            $message = $transaction->status === 'verified'
                ? 'Payment was already verified; your access remains available.'
                : 'Payment was received but remains under manual review; no additional access was granted.';
            if ($transaction->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', $message);
            }
            $reviewSlug = $this->storefrontSlugFor($transaction);
            return $reviewSlug
                ? redirect('/ebooks/' . rawurlencode((string) $reviewSlug))->with('status', $message)
                : redirect('/ebooks')->with('status', $message);
        }
        if ($transaction->learn_earn_exam_id) {
            return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->withErrors(['payment' => 'The provider return did not verify the payment. The transaction remains pending; do not retry until support reconciles its status.']);
        }
        $slug = $this->storefrontSlugFor($transaction);
        return $slug
            ? redirect('/ebooks/' . rawurlencode($slug))->withErrors(['payment' => 'The provider return did not verify the payment. The transaction remains pending; do not retry until support reconciles its status.'])
            : redirect('/ebooks')->withErrors(['payment' => 'The provider return did not verify the payment. The transaction remains pending; do not retry until support reconciles its status.']);
    }

    public function sslcommerzCancelled(Request $request): RedirectResponse
    {
        $transaction = $this->storefrontGateway->markSslcommerzFailed($request->all(), 'cancelled');
        $checkoutNotes = json_decode((string) $transaction->notes, true);
        if (is_array($checkoutNotes) && in_array($checkoutNotes['purpose'] ?? null, ['course_cart', 'course_gift', 'course_gift_voucher'], true)) {
            if (in_array($transaction->status, ['verified', 'risk_review'], true)) {
                return redirect('/student/course-commerce/cart')->with('status', $transaction->status === 'verified'
                    ? 'Payment was already verified; your course or gift access remains available.'
                    : 'Payment requires manual review. No course or gift access has been granted yet.');
            }
            return redirect('/student/course-commerce/cart')->with('status', 'The browser return cannot confirm cancellation. The transaction remains pending; do not retry until support reconciles its status.');
        }
        if (in_array($transaction->status, ['verified', 'risk_review'], true)) {
            $message = $transaction->status === 'verified'
                ? 'Payment was already verified; your access remains available.'
                : 'Payment was received but remains under manual review; no additional access was granted.';
            if ($transaction->learn_earn_exam_id) {
                return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', $message);
            }
            $reviewSlug = $this->storefrontSlugFor($transaction);
            return $reviewSlug
                ? redirect('/ebooks/' . rawurlencode((string) $reviewSlug))->with('status', $message)
                : redirect('/ebooks')->with('status', $message);
        }
        if ($transaction->learn_earn_exam_id) {
            return redirect('/learn-and-earn/exams/' . $transaction->learn_earn_exam_id)->with('status', 'The browser return cannot confirm cancellation. The transaction remains pending; do not retry until support reconciles its status.');
        }
        $slug = $this->storefrontSlugFor($transaction);
        return $slug
            ? redirect('/ebooks/' . rawurlencode($slug))->with('status', 'The browser return cannot confirm cancellation. The transaction remains pending; do not retry until support reconciles its status.')
            : redirect('/ebooks')->with('status', 'The browser return cannot confirm cancellation. The transaction remains pending; do not retry until support reconciles its status.');
    }

    private function storefrontSlugFor(UniversalPaymentTransaction $transaction): ?string
    {
        if (!$transaction->storefront_item_id
            || !Schema::hasTable('storefront_items')
            || !Schema::hasColumn('storefront_items', 'slug')) {
            return null;
        }

        $slug = DB::table('storefront_items')
            ->where('id', $transaction->storefront_item_id)
            ->value('slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private function assertInvoiceEnrollmentBranchAccess(object $invoice): void
    {
        if (!Schema::hasTable('enrollments')
            || !Schema::hasColumn('enrollments', 'invoice_id')
            || !Schema::hasColumn('enrollments', 'course_id')
            || !Schema::hasColumn('enrollments', 'user_id')
            || !Schema::hasTable('courses')
            || !Schema::hasColumn('courses', 'branch_id')) {
            return;
        }

        $pending = DB::table('enrollments')
            ->where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->get();

        foreach ($pending as $enrollment) {
            $buyer = User::query()->find((int) $enrollment->user_id);
            abort_unless($buyer !== null, 422, 'The enrolled student account no longer exists.');

            $courseBranchId = DB::table('courses')->where('id', $enrollment->course_id)->value('branch_id');
            $invoiceBranchId = Schema::hasColumn('fee_invoices', 'branch_id') && $invoice->branch_id !== null
                ? (int) $invoice->branch_id
                : null;
            $enrollmentBranchId = Schema::hasColumn('enrollments', 'branch_id') && $enrollment->branch_id !== null
                ? (int) $enrollment->branch_id
                : null;

            $assignedBranchId = null;
            if ($this->plugins->isEnabled('student-management')
                && Schema::hasTable('students')
                && Schema::hasColumn('students', 'branch_id')) {
                $profileBranchId = DB::table('students')->where('user_id', $buyer->id)->value('branch_id');
                if ($profileBranchId !== null) {
                    $assignedBranchId = (int) $profileBranchId;
                }
            }
            if ($assignedBranchId === null
                && Schema::hasColumn('users', 'branch_id')
                && $buyer->branch_id !== null) {
                $assignedBranchId = (int) $buyer->branch_id;
            }

            abort_if(
                $invoiceBranchId !== null && $assignedBranchId !== $invoiceBranchId,
                422,
                'Student branch assignment changed after invoice creation; manual payment reconciliation is required.'
            );
            abort_if(
                $enrollmentBranchId !== null && $assignedBranchId !== $enrollmentBranchId,
                422,
                'Pending enrollment branch does not match the current student branch.'
            );
            abort_if(
                $courseBranchId !== null
                    && ($enrollmentBranchId !== (int) $courseBranchId || $assignedBranchId !== (int) $courseBranchId),
                422,
                'Pending enrollment branch does not match the source course and current student branch.'
            );
        }
    }

    private function assertGatewayEnabled(string $gateway): void
    {
        if (in_array($gateway, ['cash', 'bank_transfer', 'cheque'], true)) {
            $pluginSlug = $gateway === 'cash' ? 'gateway-cash' : 'gateway-bank-transfer';
            abort_unless(
                $this->plugins->isEnabled($pluginSlug)
                    && (string) $this->plugins->getSetting($pluginSlug, 'enabled', '0') === '1',
                422,
                'The selected manual payment plugin is not installed, enabled, and configured.'
            );
        }

        // Keep the legacy bank_cash/offline identifiers subject to the same
        // global offline-payment kill switch as the new manual payment plugins.
        if (in_array($gateway, ['cash', 'bank_transfer', 'bank_cash', 'offline', 'cheque'], true)) {
            abort_unless(
                (string) $this->plugins->getSetting('universal-payment', 'allow_offline_payments', '1') === '1',
                422,
                'Manual cash and bank payments are disabled by the administrator.'
            );
        }

        $enabledRaw = $this->plugins->getSetting(
            'universal-payment',
            'enabled_gateways',
            'sslcommerz,stripe,paypal,bkash,nagad,rocket,card,cash,bank_transfer,bank_cash,offline,cheque'
        );
        $enabled = array_filter(array_map('trim', explode(',', (string) $enabledRaw)));

        abort_unless(
            in_array($gateway, $enabled, true),
            422,
            'The selected payment method is currently disabled by the administrator.'
        );
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
