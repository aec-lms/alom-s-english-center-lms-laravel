<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use RuntimeException;

class RefundSettlementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function recordOfflineRefund(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        ?int $actorId,
        ?string $providerReference = null,
    ): PaymentRefund {
        $invoiceId = PaymentTransaction::query()->whereKey($transaction->id)->value('invoice_id');

        $refund = DB::transaction(function () use ($transaction, $invoiceId, $amount, $reason, $actorId, $providerReference): PaymentRefund {
            $invoice = $invoiceId !== null && Schema::hasTable('fee_invoices')
                ? FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail()
                : null;
            $lockedTx = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($lockedTx->status !== 'settled') {
                throw new RuntimeException('Only settled transactions can be refunded.');
            }
            $this->assertActorBranchScope($actorId, $lockedTx);

            $refundAmount = round($amount, 2);
            $this->assertRefundAmountAvailable($lockedTx, $refundAmount);
            $this->assertInvoiceRefundAmountAvailable($invoice, $refundAmount);
            $orders = $this->lockCommerceOrders($invoice?->id);
            $this->assertRefundContextIntegrity($lockedTx, $invoice, $orders);
            $this->assertNoGiftItems($orders);

            $refund = PaymentRefund::query()->create([
                'payment_transaction_id' => $lockedTx->id,
                'amount' => $refundAmount,
                'reason' => $reason,
                'provider_refund_reference' => $providerReference,
                'gateway_slug' => 'offline',
                'status' => RefundProviderResult::SUCCEEDED,
                'requested_by' => $actorId,
                'approved_by' => $actorId,
                'succeeded_at' => now(),
            ]);

            $this->applyConfirmedLedgerEffects($lockedTx, $invoice, $refund, $orders, $actorId);

            return $refund->fresh();
        }, 3);

        return $refund;
    }

    public function applyProviderConfirmedRefund(PaymentRefund $refund): PaymentRefund
    {
        $transactionId = (int) $refund->payment_transaction_id;
        $invoiceId = PaymentTransaction::query()->whereKey($transactionId)->value('invoice_id');

        $applied = DB::transaction(function () use ($refund, $transactionId, $invoiceId): PaymentRefund {
            if ($invoiceId === null) {
                throw new RuntimeException('A provider refund cannot be applied without its original invoice.');
            }
            $invoice = FeeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $lockedTx = PaymentTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $orders = $this->lockCommerceOrders($invoice?->id);
            $lockedRefund = PaymentRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($lockedRefund->ledger_applied_at !== null) {
                return $lockedRefund;
            }
            $finalProviderStatuses = match ((string) $lockedRefund->gateway_slug) {
                'gateway-stripe' => ['succeeded'],
                'gateway-paypal' => ['COMPLETED'],
                'gateway-sslcommerz' => ['refunded'],
                default => [],
            };
            if (!in_array($lockedRefund->status, ['submitted', 'pending', 'requires_review', RefundProviderResult::SUCCEEDED], true)
                || !in_array((string) $lockedRefund->provider_status, $finalProviderStatuses, true)) {
                throw new RuntimeException('A final provider-confirmed refund state is required before ledger settlement.');
            }
            if ($lockedTx->status !== 'settled' && $lockedTx->status !== 'refunded') {
                throw new RuntimeException('The source payment is not in a refundable settled state.');
            }

            $this->assertRefundAmountAvailable($lockedTx, (float) $lockedRefund->amount, (int) $lockedRefund->id);
            $this->assertInvoiceRefundAmountAvailable($invoice, (float) $lockedRefund->amount, (int) $lockedRefund->id);
            $this->assertRefundContextIntegrity($lockedTx, $invoice, $orders);
            $this->assertNoGiftItems($orders);
            $this->applyConfirmedLedgerEffects($lockedTx, $invoice, $lockedRefund, $orders, $lockedRefund->approved_by);

            return $lockedRefund->fresh();
        }, 3);

        return $applied;
    }

    public function assertInvoiceRefundAmountAvailable(
        ?FeeInvoice $invoice,
        float $amount,
        ?int $excludeRefundId = null,
    ): void {
        if ($invoice === null) {
            return;
        }

        $query = PaymentRefund::query()
            ->whereHas('transaction', fn ($transaction) => $transaction->where('invoice_id', $invoice->id))
            ->where(function ($statusQuery): void {
                $statusQuery
                    ->whereIn('status', ['requested', 'submitted', 'pending', 'requires_review'])
                    ->orWhere(function ($succeededQuery): void {
                        $succeededQuery->where('status', 'succeeded')->whereNull('ledger_applied_at');
                    });
            });
        if ($excludeRefundId !== null) {
            $query->where('id', '<>', $excludeRefundId);
        }

        $reserved = round((float) $query->sum('amount'), 2);
        abort_unless(
            round($reserved + $amount, 2) <= (float) $invoice->paid_amount,
            422,
            'Refund exceeds the remaining paid invoice balance.'
        );
    }

    private function assertRefundAmountAvailable(
        PaymentTransaction $transaction,
        float $amount,
        ?int $excludeRefundId = null,
    ): void {
        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        $query = PaymentRefund::query()
            ->where('payment_transaction_id', $transaction->id)
            ->whereIn('status', [
                'requested',
                'submitted',
                'pending',
                'requires_review',
                'succeeded',
            ]);
        if ($excludeRefundId !== null) {
            $query->where('id', '<>', $excludeRefundId);
        }
        $reserved = round((float) $query->sum('amount'), 2);
        if ($reserved + $amount > (float) $transaction->amount) {
            throw new RuntimeException('Refund exceeds the remaining refundable transaction amount.');
        }
    }

    /**
     * Match settlement's lock order: invoice -> transaction -> buyer -> order.
     * The buyer row is locked before order rows because referral settlement
     * serializes first-purchase and refund reversals on the buyer account.
     */
    private function lockCommerceOrders(?int $invoiceId): array
    {
        if ($invoiceId === null
            || !Schema::hasTable('commerce_orders')
            || !Schema::hasColumn('commerce_orders', 'user_id')) {
            return [];
        }

        $buyerIds = DB::table('commerce_orders')
            ->where('invoice_id', $invoiceId)
            ->whereNotNull('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');
        if ($buyerIds->count() > 1) {
            throw new RuntimeException('One invoice cannot refund commerce orders belonging to multiple buyers.');
        }
        if ($buyerIds->isNotEmpty()) {
            User::query()->whereKey((int) $buyerIds->first())->lockForUpdate()->firstOrFail();
        }

        return DB::table('commerce_orders')
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    public function assertActorBranchScope(?int $actorId, PaymentTransaction $transaction): void
    {
        if ($actorId === null) {
            return;
        }

        $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        $branchId = (int) ($actor->branch_id ?? 0);
        $invoiceBranchId = $transaction->invoice_id !== null
            && Schema::hasTable('fee_invoices')
            && Schema::hasColumn('fee_invoices', 'branch_id')
            ? (int) (DB::table('fee_invoices')->where('id', $transaction->invoice_id)->value('branch_id') ?? 0)
            : (int) ($transaction->branch_id ?? 0);
        abort_unless(
            $branchId > 0
                && $branchId === (int) ($transaction->branch_id ?? 0)
                && $branchId === $invoiceBranchId
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $branchId)->where('is_active', true)->exists(),
            403,
            'Branch manager cannot refund a payment outside the current active branch assignment.'
        );
    }

    public function assertNoGiftOrderForInvoice(
        ?int $invoiceId,
        ?FeeInvoice $invoice = null,
        ?PaymentTransaction $transaction = null,
    ): void {
        $orders = $this->lockCommerceOrders($invoiceId);
        $this->assertRefundContextIntegrity($transaction, $invoice, $orders);
        $this->assertNoGiftItems($orders);
    }

    private function assertRefundContextIntegrity(
        ?PaymentTransaction $transaction,
        ?FeeInvoice $invoice,
        array $orders,
    ): void {
        if ($invoice === null) {
            if ($transaction !== null && $transaction->invoice_id !== null) {
                throw new RuntimeException('The original invoice is unavailable for refund validation.');
            }
            return;
        }

        if ($transaction !== null && Schema::hasColumn('fee_invoices', 'branch_id')) {
            $invoiceBranchId = $invoice->branch_id !== null ? (int) $invoice->branch_id : null;
            $transactionBranchId = $transaction->branch_id !== null ? (int) $transaction->branch_id : null;
            if ($invoiceBranchId !== $transactionBranchId) {
                throw new RuntimeException('Payment transaction branch does not match its original invoice.');
            }
        }

        if (!Schema::hasColumn('fee_invoices', 'student_id') || $invoice->student_id === null) {
            return;
        }
        if (!Schema::hasTable('students')
            || !Schema::hasColumn('students', 'user_id')) {
            throw new RuntimeException('The invoice student identity cannot be verified; refund is blocked.');
        }

        $studentQuery = DB::table('students')->where('id', $invoice->student_id);
        if (Schema::hasColumn('students', 'deleted_at')) {
            $studentQuery->whereNull('deleted_at');
        }
        $studentSnapshot = $studentQuery->first();
        if ($studentSnapshot === null || $studentSnapshot->user_id === null) {
            throw new RuntimeException('The invoice student profile is unavailable; refund is blocked.');
        }

        $studentUserId = (int) $studentSnapshot->user_id;
        foreach ($orders as $order) {
            if ($order->user_id === null || (int) $order->user_id !== $studentUserId) {
                throw new RuntimeException('Commerce order buyer does not match the invoice student account.');
            }
        }

        // Student lifecycle writers lock User before Student. Keep the same
        // order when validating the live identity and branch assignment.
        $studentUser = User::query()->whereKey($studentUserId)->lockForUpdate()->firstOrFail();
        $lockedStudentQuery = DB::table('students')->where('id', $invoice->student_id);
        if (Schema::hasColumn('students', 'deleted_at')) {
            $lockedStudentQuery->whereNull('deleted_at');
        }
        $lockedStudent = $lockedStudentQuery->lockForUpdate()->first();
        if ($lockedStudent === null || (int) $lockedStudent->user_id !== $studentUserId) {
            throw new RuntimeException('Invoice student identity changed during refund validation.');
        }

        $studentBranchId = Schema::hasColumn('students', 'branch_id') && $lockedStudent->branch_id !== null
            ? (int) $lockedStudent->branch_id
            : null;
        $userBranchId = Schema::hasColumn('users', 'branch_id') && $studentUser->branch_id !== null
            ? (int) $studentUser->branch_id
            : null;
        if ($studentBranchId !== null && $userBranchId !== null && $studentBranchId !== $userBranchId) {
            throw new RuntimeException('Student profile and account branch assignments do not match.');
        }

        $invoiceBranchId = Schema::hasColumn('fee_invoices', 'branch_id') && $invoice->branch_id !== null
            ? (int) $invoice->branch_id
            : null;
        $assignedBranchId = $studentBranchId ?? $userBranchId;
        if ($invoiceBranchId !== $assignedBranchId) {
            throw new RuntimeException('Invoice student branch does not match the original payment branch.');
        }
        if ($transaction !== null
            && (int) ($transaction->branch_id ?? 0) !== (int) ($assignedBranchId ?? 0)) {
            throw new RuntimeException('Student branch does not match the original payment transaction branch.');
        }
    }

    private function assertNoGiftItems(array $orders): void
    {
        if ($orders === [] || !Schema::hasTable('commerce_order_items')) {
            return;
        }

        $orderIds = array_map(static fn ($order): int => (int) $order->id, $orders);
        $containsGift = DB::table('commerce_order_items')
            ->whereIn('order_id', $orderIds)
            ->whereIn('item_type', ['gift_course', 'gift_voucher'])
            ->exists();

        if ($containsGift) {
            throw new RuntimeException(
                'Refunds for orders containing course gifts or gift vouchers are blocked until recipient access and voucher-wallet reconciliation are supported. No refund was recorded.'
            );
        }
    }

    private function applyConfirmedLedgerEffects(
        PaymentTransaction $transaction,
        ?FeeInvoice $invoice,
        PaymentRefund $refund,
        array $orders,
        ?int $actorId,
    ): void {
        if ($refund->ledger_applied_at !== null) {
            return;
        }
        if ((string) $refund->gateway_slug !== (string) $transaction->gateway_slug) {
            throw new RuntimeException('Refund gateway does not match the original payment transaction.');
        }
        if ((float) $refund->amount <= 0 || (float) $refund->amount > (float) $transaction->amount) {
            throw new RuntimeException('Refund amount is outside the original captured transaction amount.');
        }
        if ($transaction->gateway_slug !== 'offline' && trim((string) $refund->provider_refund_reference) === '') {
            throw new RuntimeException('A provider refund reference is required before applying an online refund to the ledger.');
        }
        if ($invoice !== null
            && strtoupper((string) $transaction->currency) !== strtoupper((string) $invoice->currency)) {
            throw new RuntimeException('Refund transaction currency no longer matches its invoice.');
        }

        $succeededTotal = round(
            (float) PaymentRefund::query()
                ->where('payment_transaction_id', $transaction->id)
                ->where('status', RefundProviderResult::SUCCEEDED)
                ->sum('amount'),
            2
        );
        if ($refund->status !== RefundProviderResult::SUCCEEDED) {
            $succeededTotal = round($succeededTotal + (float) $refund->amount, 2);
        }
        if ($succeededTotal > (float) $transaction->amount) {
            throw new RuntimeException('Confirmed refunds exceed the captured transaction amount.');
        }

        if ($succeededTotal >= (float) $transaction->amount) {
            $transaction->update(['status' => 'refunded']);
        }

        if ($invoice !== null) {
            $newPaid = max(0.0, round((float) $invoice->paid_amount - (float) $refund->amount, 2));
            $invoice->update([
                'paid_amount' => $newPaid,
                'status' => $newPaid <= 0 ? 'unpaid' : ($newPaid + 0.01 >= (float) $invoice->net_amount ? 'paid' : 'partial'),
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

            if ($orders !== []
                && Schema::hasTable('referral_commissions')
                && Schema::hasTable('referral_commission_reversals')) {
                $order = $orders[0];
                $buyer = User::query()->find((int) $order->user_id);
                if ($buyer) {
                    app(ReferralIncomeService::class)->reverseSettledPurchase(
                        $buyer,
                        'commerce_order',
                        (string) $order->id,
                        (int) $refund->id,
                        (float) $refund->amount,
                        (string) $transaction->currency,
                    );
                }
            }
        }

        $refund->update([
            'status' => RefundProviderResult::SUCCEEDED,
            'succeeded_at' => $refund->succeeded_at ?? now(),
            'ledger_applied_at' => now(),
        ]);

        $event = $refund->gateway_slug === 'offline'
            ? 'payment.refunded'
            : 'payment.refund_provider_confirmed';
        $this->audit->log($event, 'payment_transaction', (string) $transaction->id, [
            'refund_id' => $refund->id,
            'amount' => $refund->amount,
            'gateway' => $refund->gateway_slug,
            'provider_status' => $refund->provider_status,
        ], $actorId);
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
            ->orderBy('id')
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
}
