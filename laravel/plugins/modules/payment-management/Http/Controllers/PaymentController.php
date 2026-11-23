<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentSettlementService $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $invoiceIntegrationEnabled = $this->invoiceIntegrationEnabled();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:initiated,settled,failed,refunded'],
        ]);
        $transactionQuery = PaymentTransaction::query()
            ->when($invoiceIntegrationEnabled, fn ($q) => $q->with('invoice'))
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('settled_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('settled_at', '<=', $v))
            ->when($filters['gateway'] ?? null, fn ($q, $v) => $q->where('gateway_slug', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        $transactions = (clone $transactionQuery)->latest()->paginate(25)->withQueryString();
        $openInvoices = $invoiceIntegrationEnabled
            ? FeeInvoice::query()->whereIn('status', ['unpaid', 'partial'])
                ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderBy('due_date')->limit(500)->get()
            : collect();
        $settledQuery = PaymentTransaction::query()->where('status', 'settled')->whereNotNull('settled_at')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('settled_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('settled_at', '<=', $v));
        $collectionTotals = [
            'today' => round((float) (clone $settledQuery)->whereDate('settled_at', now()->toDateString())->sum('amount'), 2),
            'period' => round((float) (clone $settledQuery)->sum('amount'), 2),
            'outstanding' => round((float) $openInvoices->sum(fn ($invoice) => max(0, (float) $invoice->net_amount - (float) $invoice->paid_amount)), 2),
            'overdue' => $openInvoices->filter(fn ($invoice) => $invoice->due_date && $invoice->due_date->isPast())->count(),
            'partial' => $openInvoices->where('status', 'partial')->count(),
            'unpaid' => $openInvoices->where('status', 'unpaid')->count(),
        ];

        return view('payment-management::index', compact('transactions', 'invoiceIntegrationEnabled', 'openInvoices', 'collectionTotals', 'filters'));
    }

    public function exportCollections(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:initiated,settled,failed,refunded'],
        ]);
        $query = DB::table('payment_transactions')
            ->leftJoin('fee_invoices', 'fee_invoices.id', '=', 'payment_transactions.invoice_id')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('payment_transactions.branch_id', $actor->branch_id))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('payment_transactions.settled_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('payment_transactions.settled_at', '<=', $v))
            ->when($filters['gateway'] ?? null, fn ($q, $v) => $q->where('payment_transactions.gateway_slug', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('payment_transactions.status', $v))
            ->select([
                'payment_transactions.transaction_ref', 'payment_transactions.gateway_slug',
                'payment_transactions.amount', 'payment_transactions.currency',
                'payment_transactions.status', 'payment_transactions.external_reference',
                'payment_transactions.settled_at', 'fee_invoices.invoice_number',
                'fee_invoices.payer_name', 'payment_transactions.branch_id',
            ]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Transaction Ref', 'Invoice', 'Payer', 'Gateway', 'Amount', 'Currency', 'Status', 'External Reference', 'Settled At', 'Branch ID']);
            $query->orderByDesc('payment_transactions.settled_at')->chunk(500, function ($rows) use ($out): void {
                $safe = static fn ($value): string => preg_match('/^[=+@\\\\-\\t\\r]/', (string) $value) ? "'".(string) $value : (string) $value;
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $safe($row->transaction_ref), $safe($row->invoice_number), $safe($row->payer_name),
                        $row->gateway_slug, $row->amount, $row->currency, $row->status,
                        $safe($row->external_reference), $row->settled_at, $row->branch_id,
                    ]);
                }
            });
            fclose($out);
        }, 'payment-collections-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function recordOffline(Request $request): RedirectResponse
    {
        abort_unless($this->invoiceIntegrationEnabled(), 503, 'Enable Fees Management before recording invoice payments.');
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'receipt_note' => ['nullable', 'string', 'max:160'],
        ]);

        $invoice = FeeInvoice::query()->findOrFail((int) $validated['invoice_id']);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $invoice->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $tx = DB::transaction(function () use ($invoice, $validated, $actor): PaymentTransaction {
            $lockedInvoice = FeeInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $lockedInvoice->branch_id) {
                abort(403, 'Branch isolation violation.');
            }
            $remaining = round((float) $lockedInvoice->net_amount - (float) $lockedInvoice->paid_amount, 2);
            $amount = round((float) $validated['amount'], 2);
            if ($remaining <= 0 || $amount > $remaining + 0.01) {
                abort(422, 'Offline payment amount exceeds the remaining invoice balance.');
            }

            $transaction = PaymentTransaction::query()->create([
                'transaction_ref' => 'TXN-' . strtoupper(bin2hex(random_bytes(6))),
                'invoice_id' => $lockedInvoice->id,
                'branch_id' => $lockedInvoice->branch_id,
                'gateway_slug' => 'offline',
                'amount' => $amount,
                'currency' => $lockedInvoice->currency,
                'status' => 'initiated',
                'metadata' => ['note' => $validated['receipt_note'] ?? 'Cash/Bank Desk'],
            ]);

            return $this->settlement->settleTransaction($transaction, 'OFFLINE-' . $transaction->id, [], $actor?->id);
        });

        return back()->with('status', "Offline payment {$tx->transaction_ref} recorded and settled.");
    }

    public function refund(Request $request, PaymentTransaction $transaction): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $transaction->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        if ($transaction->gateway_slug !== 'offline') {
            return back()->withErrors([
                'refund' => 'Online refunds are disabled until this gateway has a verified provider refund adapter. No refund record or invoice balance was changed.',
            ]);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'provider_refund_reference' => [$transaction->gateway_slug === 'offline' ? 'nullable' : 'required', 'string', 'max:160'],
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($transaction, $validated, $actor): void {
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $lockedTx->branch_id) {
                    throw new \RuntimeException('Branch isolation violation.');
                }
                if ($lockedTx->status !== 'settled') {
                    throw new \RuntimeException('Only settled transactions can be refunded.');
                }

                // Gift/voucher fulfillment is recipient-specific. Until provider
                // refunds and voucher-wallet reversals can revoke unused and
                // already-redeemed gifts safely, fail closed before any ledger
                // or invoice mutation.
                if ($lockedTx->invoice_id
                    && Schema::hasTable('commerce_orders')
                    && Schema::hasTable('commerce_order_items')) {
                    $orderIds = DB::table('commerce_orders')
                        ->where('invoice_id', $lockedTx->invoice_id)
                        ->lockForUpdate()
                        ->pluck('id');
                    if ($orderIds->isNotEmpty()
                        && DB::table('commerce_order_items')
                            ->whereIn('order_id', $orderIds)
                            ->whereIn('item_type', ['gift_course', 'gift_voucher'])
                            ->exists()) {
                        throw new \RuntimeException(
                            'Refunds for orders containing course gifts or gift vouchers are blocked until recipient access and voucher-wallet reconciliation are supported. No refund was recorded.'
                        );
                    }
                }

                $alreadyRefunded = (float) $lockedTx->refunds()->sum('amount');
                $refundAmount = round((float) $validated['amount'], 2);
                if ($refundAmount + $alreadyRefunded > (float) $lockedTx->amount + 0.01) {
                    throw new \RuntimeException('Refund exceeds remaining refundable transaction amount.');
                }

                $refund = PaymentRefund::query()->create([
                    'payment_transaction_id' => $lockedTx->id,
                    'amount' => $refundAmount,
                    'reason' => $validated['reason'],
                    'provider_refund_reference' => $validated['provider_refund_reference'] ?? null,
                    'approved_by' => $actor?->id,
                ]);

                // Financial reversals remain active even if the optional referral
                // plugin has since been disabled; only its installed ledger tables matter.
                if ($lockedTx->invoice_id
                    && Schema::hasTable('commerce_orders')
                    && Schema::hasTable('referral_commissions')
                    && Schema::hasTable('referral_commission_reversals')) {
                    $order = DB::table('commerce_orders')
                        ->where('invoice_id', $lockedTx->invoice_id)
                        ->lockForUpdate()->first();
                    if ($order) {
                        $buyer = \App\Models\User::query()->find((int) $order->user_id);
                        if ($buyer) {
                            app(\Plugins\Modules\ReferralIncome\Services\ReferralIncomeService::class)
                                ->reverseSettledPurchase(
                                    $buyer,
                                    'commerce_order',
                                    (string) $order->id,
                                    (int) $refund->id,
                                    $refundAmount,
                                    (string) $lockedTx->currency,
                                );
                        }
                    }
                }

                $totalRefunded = round($alreadyRefunded + $refundAmount, 2);
                if ($totalRefunded >= (float) $lockedTx->amount) {
                    $lockedTx->update(['status' => 'refunded']);
                }

                if ($this->invoiceIntegrationEnabled() && ($invoice = FeeInvoice::query()->where('id', $lockedTx->invoice_id)->lockForUpdate()->first())) {
                    $newPaid = max(0.0, round((float) $invoice->paid_amount - $refundAmount, 2));
                    $invoice->update([
                        'paid_amount' => $newPaid,
                        'status' => $newPaid <= 0 ? 'unpaid' : ($newPaid >= (float) $invoice->net_amount ? 'paid' : 'partial'),
                    ]);

                    if ($newPaid + 0.01 < (float) $invoice->net_amount
                        && Schema::hasTable('enrollments')
                        && Schema::hasColumn('enrollments', 'invoice_id')) {
                        DB::table('enrollments')
                            ->where('invoice_id', $invoice->id)
                            ->where('status', 'active')
                            ->update(['status' => 'pending', 'updated_at' => now()]);
                    }

                    $this->suspendCommerceAccessAfterRefund($invoice, $newPaid);
                }
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $this->audit->log('payment.refunded', 'payment_transaction', (string) $transaction->id, ['amount' => $validated['amount']], $actor?->id);

        return back()->with('status', "Refund recorded for {$transaction->transaction_ref}.");
    }
    private function suspendCommerceAccessAfterRefund(FeeInvoice $invoice, float $newPaid): void
    {
        if ($newPaid + 0.01 >= (float) $invoice->net_amount
            || !Schema::hasTable('commerce_orders')
            || !Schema::hasTable('commerce_order_items')) {
            return;
        }

        $orders = DB::table('commerce_orders')
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();
        if ($orders->isEmpty()) {
            return;
        }

        $paymentIds = DB::table('payment_transactions')
            ->where('invoice_id', $invoice->id)
            ->pluck('id');

        foreach ($orders as $order) {
            DB::table('commerce_orders')->where('id', $order->id)->update([
                'status' => $newPaid <= 0.01 ? 'refunded' : 'partially_refunded',
                'payment_status' => $newPaid <= 0.01 ? 'refunded' : 'partial',
                'updated_at' => now(),
            ]);

            $items = DB::table('commerce_order_items')->where('order_id', $order->id)->get();
            $itemIds = $items->pluck('id');
            if (Schema::hasTable('commerce_entitlements') && $itemIds->isNotEmpty()) {
                DB::table('commerce_entitlements')
                    ->where('user_id', $order->user_id)
                    ->whereIn('order_item_id', $itemIds)
                    ->where('status', 'active')
                    ->update(['status' => 'suspended', 'updated_at' => now()]);
            }

            foreach ($items as $item) {
                if ($item->item_type === 'mock_test' && $item->item_id
                    && Schema::hasTable('learn_earn_exam_access')
                    && Schema::hasColumn('learn_earn_exam_access', 'payment_reference')) {
                    DB::table('learn_earn_exam_access')
                        ->where('exam_id', $item->item_id)
                        ->where('user_id', $order->user_id)
                        ->where('payment_reference', $order->order_number)
                        ->where('status', 'granted')
                        ->update(['status' => 'revoked', 'updated_at' => now()]);
                }

                if ($item->item_type === 'ebook' && $item->item_id
                    && $paymentIds->isNotEmpty()
                    && Schema::hasTable('storefront_item_access')
                    && Schema::hasColumn('storefront_item_access', 'payment_transaction_id')) {
                    DB::table('storefront_item_access')
                        ->where('storefront_item_id', $item->item_id)
                        ->where('user_id', $order->user_id)
                        ->whereIn('payment_transaction_id', $paymentIds)
                        ->delete();
                }
            }
        }
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !app(PluginManager::class)->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
        $activeBranch = DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }

    private function invoiceIntegrationEnabled(): bool
    {
        return app(PluginManager::class)->isEnabled('fees-management')
            && Schema::hasTable('fee_invoices');
    }

}
