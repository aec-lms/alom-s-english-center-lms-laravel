<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
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
use Plugins\Modules\PaymentManagement\Services\RefundOrchestrationService;
use Plugins\Modules\PaymentManagement\Services\RefundSettlementService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentSettlementService $settlement,
        private readonly AuditLogger $audit,
        private readonly RefundSettlementService $refundSettlement,
        private readonly RefundOrchestrationService $refundOrchestration,
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
        $refunds = PaymentRefund::query()
            ->with(['transaction', 'requester', 'approver'])
            ->when($actor && $actor->hasRole('branch_manager'), function ($q) use ($actor): void {
                $q->whereHas('transaction', fn ($tx) => $tx->where('branch_id', $actor->branch_id));
            })
            ->latest()
            ->limit(50)
            ->get();

        return view('payment-management::index', compact('transactions', 'invoiceIntegrationEnabled', 'refunds'));
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

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'provider_refund_reference' => ['nullable', 'string', 'max:160'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:120'],
        ]);

        try {
            if ($transaction->gateway_slug === 'offline') {
                $refund = $this->refundSettlement->recordOfflineRefund(
                    $transaction,
                    (float) $validated['amount'],
                    trim($validated['reason']),
                    $actor?->id,
                    $validated['provider_refund_reference'] ?? null,
                );

                return back()->with('status', "Offline refund #{$refund->id} recorded and reconciled.");
            }

            $idempotencyKey = trim((string) ($validated['idempotency_key'] ?? $request->header('Idempotency-Key', '')));
            abort_unless($idempotencyKey !== '', 422, 'An idempotency key is required for an online refund request.');

            $refund = $this->refundOrchestration->requestOnlineRefund(
                $transaction,
                (float) $validated['amount'],
                trim($validated['reason']),
                (int) $actor->id,
                $idempotencyKey,
            );

            return back()->with('status', "Online refund request #{$refund->id} is awaiting approval by a different authorized user.");
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()]);
        }
    }

    public function approveRefund(Request $request, PaymentRefund $refund): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        try {
            $updated = $this->refundOrchestration->approveAndSubmit($refund, (int) $actor->id);
            return back()->with('status', "Refund #{$updated->id} provider state: {$updated->status}.");
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()]);
        }
    }

    public function rejectRefund(Request $request, PaymentRefund $refund): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        try {
            $updated = $this->refundOrchestration->rejectRequest($refund, (int) $actor->id);
            return back()->with('status', "Refund request #{$updated->id} was rejected.");
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()]);
        }
    }

    public function reconcileRefund(Request $request, PaymentRefund $refund): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $refund->loadMissing('transaction');
        if ($actor && $actor->hasRole('branch_manager')
            && (int) $actor->branch_id !== (int) ($refund->transaction?->branch_id ?? 0)) {
            abort(403, 'Branch isolation violation.');
        }

        try {
            $updated = $this->refundOrchestration->reconcile($refund, (int) $actor->id);
            return back()->with('status', "Refund #{$updated->id} provider state: {$updated->status}.");
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => $exception->getMessage()]);
        }
    }

    public function exportRefundAudit(Request $request)
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $query = PaymentRefund::query()
            ->join('payment_transactions', 'payment_transactions.id', '=', 'payment_refunds.payment_transaction_id')
            ->leftJoin('users as refund_requesters', 'refund_requesters.id', '=', 'payment_refunds.requested_by')
            ->leftJoin('users as refund_approvers', 'refund_approvers.id', '=', 'payment_refunds.approved_by')
            ->leftJoin('users as refund_rejecters', 'refund_rejecters.id', '=', 'payment_refunds.rejected_by')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('payment_transactions.branch_id', $actor->branch_id))
            ->select([
                'payment_refunds.*',
                'payment_transactions.transaction_ref as transaction_reference',
                'payment_transactions.currency as transaction_currency',
                'refund_requesters.name as requester_name',
                'refund_approvers.name as approver_name',
                'refund_rejecters.name as rejecter_name',
            ])
            ->orderBy('payment_refunds.id');

        $count = (clone $query)->count('payment_refunds.id');
        $this->audit->log('payment.refund_audit_exported', 'payment_refund', 'collection', [
            'record_count' => $count,
            'branch_id' => $actor && $actor->hasRole('branch_manager') ? $actor->branch_id : null,
        ], $actor?->id);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            $escapeCsvCell = static function ($value) {
                if (!is_string($value)) {
                    return $value;
                }
                return preg_match('/^[\\s]*[=+@\\-]/u', $value) ? "'" . $value : $value;
            };

            fputcsv($output, [
                'refund_id',
                'transaction_reference',
                'gateway',
                'currency',
                'amount',
                'reason',
                'status',
                'provider_status',
                'provider_refund_reference',
                'requester',
                'approver',
                'rejecter',
                'submitted_at',
                'succeeded_at',
                'failed_at',
                'rejected_at',
                'reconciled_at',
                'ledger_applied_at',
                'created_at',
            ]);

            foreach ($query->cursor() as $refund) {
                fputcsv($output, array_map($escapeCsvCell, [
                    $refund->id,
                    $refund->transaction_reference,
                    $refund->gateway_slug,
                    $refund->transaction_currency,
                    $refund->amount,
                    $refund->reason,
                    $refund->status,
                    $refund->provider_status,
                    $refund->provider_refund_reference,
                    $refund->requester_name,
                    $refund->approver_name,
                    $refund->rejecter_name,
                    $refund->submitted_at,
                    $refund->succeeded_at,
                    $refund->failed_at,
                    $refund->rejected_at,
                    $refund->reconciled_at,
                    $refund->ledger_applied_at,
                    $refund->created_at,
                ]));
            }

            fclose($output);
        }, 'payment-refund-audit-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
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
