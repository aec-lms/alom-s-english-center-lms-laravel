<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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
        $transactions = PaymentTransaction::query()
            ->when($invoiceIntegrationEnabled, fn ($q) => $q->with('invoice'))
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(25);

        return view('payment-management::index', compact('transactions', 'invoiceIntegrationEnabled'));
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
                    throw new \\RuntimeException('Branch isolation violation.');
                }
                if ($lockedTx->status !== 'settled') {
                    throw new \RuntimeException('Only settled transactions can be refunded.');
                }

                $alreadyRefunded = (float) $lockedTx->refunds()->sum('amount');
                $refundAmount = round((float) $validated['amount'], 2);
                if ($refundAmount + $alreadyRefunded > (float) $lockedTx->amount + 0.01) {
                    throw new \RuntimeException('Refund exceeds remaining refundable transaction amount.');
                }

                PaymentRefund::query()->create([
                    'payment_transaction_id' => $lockedTx->id,
                    'amount' => $refundAmount,
                    'reason' => $validated['reason'],
                    'provider_refund_reference' => $validated['provider_refund_reference'] ?? null,
                    'approved_by' => $actor?->id,
                ]);

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
                }
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $this->audit->log('payment.refunded', 'payment_transaction', (string) $transaction->id, ['amount' => $validated['amount']], $actor?->id);

        return back()->with('status', "Refund recorded for {$transaction->transaction_ref}.");
    }
    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        if ($actor->branch_id === null || !app(PluginManager::class)->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }

        $activeBranch = DB::table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();

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
