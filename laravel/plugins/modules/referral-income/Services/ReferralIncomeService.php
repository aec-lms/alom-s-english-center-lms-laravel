<?php

declare(strict_types=1);

namespace Plugins\Modules\ReferralIncome\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReferralIncomeService
{
    public function createOrGetCode(User $owner): string
    {
        $type = $this->ownerType($owner);
        $existing = DB::table('referral_codes')->where('owner_user_id', $owner->id)->where('owner_type', $type)->first();
        if ($existing) {
            return (string) $existing->code;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtoupper(Str::random(10));
            try {
                DB::table('referral_codes')->insert([
                    'owner_user_id' => $owner->id, 'owner_type' => $type, 'code' => $code,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                return $code;
            } catch (\Illuminate\Database\QueryException $exception) {
                $existing = DB::table('referral_codes')->where('owner_user_id', $owner->id)->where('owner_type', $type)->first();
                if ($existing) return (string) $existing->code;
                if ($attempt === 4) throw $exception;
            }
        }

        throw new RuntimeException('Unable to create referral code.');
    }

    public function applyCode(User $referred, string $code): void
    {
        $code = strtoupper(trim($code));

        DB::transaction(function () use ($referred, $code): void {
            // The buyer row is the serialization point shared with checkout
            // settlement. Settlement paths must lock this row before marking
            // any purchase source paid.
            $buyer = User::query()->whereKey($referred->id)->lockForUpdate()->firstOrFail();
            if (Schema::hasTable('role_user')) {
                DB::table('role_user')->where('user_id', $buyer->id)->lockForUpdate()->get();
            }
            $this->ownerType($buyer);

            $record = DB::table('referral_codes')
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if (!$record || !$record->is_active) {
                throw ValidationException::withMessages(['referral_code' => 'Referral code is invalid or inactive.']);
            }
            if ((int) $record->owner_user_id === (int) $buyer->id) {
                throw ValidationException::withMessages(['referral_code' => 'You cannot use your own referral code.']);
            }

            if ($this->hasPriorPaidPurchase((int) $buyer->id)) {
                throw ValidationException::withMessages(['referral_code' => 'Referral codes must be applied before the first paid order.']);
            }

            $existing = DB::table('referral_attributions')
                ->where('referred_user_id', $buyer->id)
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages(['referral_code' => 'A referral has already been assigned to this account.']);
            }

            DB::table('referral_attributions')->insert([
                'referrer_user_id' => $record->owner_user_id,
                'referred_user_id' => $buyer->id,
                'referral_code_id' => $record->id,
                'referrer_type' => $record->owner_type,
                'status' => 'active',
                'attributed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function hasPriorPaidPurchase(int $buyerId): bool
    {
        foreach (['commerce_orders', 'course_cart_orders'] as $table) {
            if (!Schema::hasTable($table)
                || !Schema::hasColumn($table, 'user_id')
                || !Schema::hasColumn($table, 'status')) {
                continue;
            }

            if (DB::table($table)
                ->where('user_id', $buyerId)
                ->whereIn('status', ['paid', 'settled', 'completed'])
                ->exists()) {
                return true;
            }

            if (Schema::hasColumn($table, 'payment_status')
                && DB::table($table)->where('user_id', $buyerId)->whereIn('payment_status', ['paid', 'settled', 'completed'])->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Call only after a payment/order has been authoritatively verified as settled.
     * The unique source key makes webhook retries idempotent.
     */
    public function recordSettledPurchase(User $buyer, string $sourceType, string|int $sourceId, float $eligibleAmount, string $currency = 'BDT'): ?int
    {
        $sourceId = (string) $sourceId;
        if (!in_array($sourceType, ['commerce_order', 'course_cart_order'], true)
            || !ctype_digit($sourceId)
            || (int) $sourceId < 1
            || $eligibleAmount <= 0
            || strlen($sourceId) > 80) {
            return null;
        }

        return DB::transaction(function () use ($buyer, $sourceType, $sourceId, $eligibleAmount, $currency): ?int {
            $attribution = DB::table('referral_attributions')
                ->where('referred_user_id', $buyer->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (!$attribution) {
                return null;
            }

            $purchase = $this->resolveSettledPurchase($buyer, $sourceType, $sourceId);
            if (!$purchase
                || abs((float) $purchase['eligible_amount'] - $eligibleAmount) > 0.01
                || strtoupper((string) $purchase['currency']) !== strtoupper(trim($currency))) {
                return null;
            }

            $rule = DB::table('referral_income_rules')
                ->where('referrer_type', $attribution->referrer_type)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (!$rule || (float) $rule->commission_percent <= 0) {
                return null;
            }

            $authoritativeAmount = (float) $purchase['eligible_amount'];
            $authoritativeCurrency = strtoupper((string) $purchase['currency']);
            $commission = round($authoritativeAmount * ((float) $rule->commission_percent / 100), 2);
            if ($commission <= 0) {
                return null;
            }

            $inserted = DB::table('referral_commissions')->insertOrIgnore([
                'attribution_id' => $attribution->id,
                'referrer_user_id' => $attribution->referrer_user_id,
                'referred_user_id' => $buyer->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'eligible_amount' => round($authoritativeAmount, 2),
                'commission_percent' => $rule->commission_percent,
                'commission_amount' => $commission,
                'currency' => $authoritativeCurrency,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!$inserted) {
                return DB::table('referral_commissions')
                    ->where('referred_user_id', $buyer->id)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->value('id');
            }

            return (int) DB::getPdo()->lastInsertId();
        });
    }

    /**
     * Only persisted, fully paid order sources may create commissions. Caller
     * supplied amounts/currency are assertions, never the source of truth.
     *
     * @return array{eligible_amount: float, currency: string}|null
     */
    private function resolveSettledPurchase(User $buyer, string $sourceType, string $sourceId): ?array
    {
        $table = $sourceType === 'commerce_order' ? 'commerce_orders' : 'course_cart_orders';
        if (!Schema::hasTable($table)
            || !Schema::hasColumn($table, 'user_id')
            || !Schema::hasColumn($table, 'invoice_id')
            || !Schema::hasColumn($table, 'currency')
            || !Schema::hasColumn($table, 'status')) {
            return null;
        }

        $order = DB::table($table)
            ->where('id', (int) $sourceId)
            ->where('user_id', $buyer->id)
            ->lockForUpdate()
            ->first();
        if (!$order || !in_array((string) $order->status, ['paid', 'settled', 'completed'], true)) {
            return null;
        }

        if (Schema::hasColumn($table, 'payment_status')
            && !in_array((string) $order->payment_status, ['paid', 'settled'], true)) {
            return null;
        }

        $orderAmount = null;
        foreach (['net_amount', 'grand_total'] as $amountColumn) {
            if (Schema::hasColumn($table, $amountColumn)) {
                $orderAmount = (float) $order->{$amountColumn};
                break;
            }
        }
        if ($orderAmount === null || $orderAmount <= 0 || !$order->invoice_id || !Schema::hasTable('fee_invoices')) {
            return null;
        }

        $invoice = DB::table('fee_invoices')->where('id', $order->invoice_id)->lockForUpdate()->first();
        if (!$invoice
            || $invoice->status !== 'paid'
            || (float) $invoice->paid_amount + 0.01 < (float) $invoice->net_amount
            || abs($orderAmount - (float) $invoice->net_amount) > 0.01
            || strtoupper((string) $invoice->currency) !== strtoupper((string) $order->currency)) {
            return null;
        }

        if ($sourceType === 'course_cart_order') {
            if (empty($order->payment_transaction_id) || !Schema::hasTable('universal_payment_transactions')) {
                return null;
            }

            $originalPayment = DB::table('universal_payment_transactions')
                ->where('id', (int) $order->payment_transaction_id)
                ->where('user_id', $buyer->id)
                ->first();
            $originalNotes = $originalPayment ? json_decode((string) $originalPayment->notes, true) : null;
            if (!$originalPayment
                || ($originalPayment->invoice_id !== null && (int) $originalPayment->invoice_id !== (int) $order->invoice_id)
                || !in_array((string) $originalPayment->status, ['pending', 'risk_review', 'verified'], true)
                || !is_array($originalNotes)
                || ($originalNotes['purpose'] ?? null) !== 'course_cart'
                || (int) ($originalNotes['course_cart_order_id'] ?? 0) !== (int) $order->id) {
                return null;
            }
        }

        return [
            'eligible_amount' => round((float) $invoice->net_amount, 2),
            'currency' => strtoupper((string) $invoice->currency),
        ];
    }

    /**
     * Reverse commission only for an authoritative, recorded refund.
     * Each payment_refund_id is idempotent; paid or payout-committed commissions
     * are not silently edited and instead create a recovery_due ledger entry.
     */
    public function reverseSettledPurchase(
        User $buyer,
        string $sourceType,
        string|int $sourceId,
        int $paymentRefundId,
        float $refundedAmount,
        string $currency = 'BDT',
    ): ?int {
        if ($paymentRefundId < 1 || $refundedAmount <= 0
            || strlen((string) $sourceId) > 80
            || !preg_match('/^[a-z0-9_-]{2,40}$/i', $sourceType)) {
            return null;
        }

        if (!Schema::hasTable('payment_refunds') || !Schema::hasTable('referral_commission_reversals')) {
            return null;
        }

        return DB::transaction(function () use ($buyer, $sourceType, $sourceId, $paymentRefundId, $refundedAmount, $currency): ?int {
            // Lock the authoritative refund row so concurrent retries serialize
            // before checking/inserting the unique reversal record.
            $refundRecord = DB::table('payment_refunds')->where('id', $paymentRefundId)->lockForUpdate()->first();
            if (!$refundRecord || round((float) $refundRecord->amount, 2) !== round($refundedAmount, 2)) {
                throw new RuntimeException('Referral reversal must match an existing recorded payment refund.');
            }

            // Bind the refund to an authoritative, buyer-owned source order and
            // its invoice. Read the source keys first, then acquire row locks in
            // the same order as the relevant settlement path.
            if (!in_array($sourceType, ['commerce_order', 'course_cart_order'], true)
                || !Schema::hasTable('payment_transactions')
                || !Schema::hasTable('fee_invoices')) {
                throw new RuntimeException('Referral reversal source cannot be reconciled to a supported paid order.');
            }

            $sourceTable = $sourceType === 'commerce_order' ? 'commerce_orders' : 'course_cart_orders';
            if (!Schema::hasTable($sourceTable)) {
                throw new RuntimeException('Referral reversal source order table is unavailable.');
            }

            $orderSnapshot = DB::table($sourceTable)
                ->where('id', (int) $sourceId)
                ->where('user_id', $buyer->id)
                ->first();
            if (!$orderSnapshot || empty($orderSnapshot->invoice_id)) {
                throw new RuntimeException('Referral reversal order does not belong to this buyer or has no invoice.');
            }

            if ($sourceType === 'commerce_order') {
                // PaymentSettlementService locks invoice before buyer and order.
                $invoice = DB::table('fee_invoices')->where('id', $orderSnapshot->invoice_id)->lockForUpdate()->first();
                User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
                $order = DB::table($sourceTable)
                    ->where('id', (int) $sourceId)
                    ->where('user_id', $buyer->id)
                    ->lockForUpdate()
                    ->first();
            } else {
                // CourseCommercePaymentSettlementService locks Universal Payment,
                // buyer, then cart order; preserve that exact order here.
                if (empty($orderSnapshot->payment_transaction_id) || !Schema::hasTable('universal_payment_transactions')) {
                    throw new RuntimeException('Referral reversal course-cart order has no verified original payment transaction.');
                }

                $originalPayment = DB::table('universal_payment_transactions')
                    ->where('id', (int) $orderSnapshot->payment_transaction_id)
                    ->where('user_id', $buyer->id)
                    ->lockForUpdate()
                    ->first();
                $originalNotes = $originalPayment ? json_decode((string) $originalPayment->notes, true) : null;
                if (!$originalPayment
                    || ($originalPayment->invoice_id !== null && (int) $originalPayment->invoice_id !== (int) $orderSnapshot->invoice_id)
                    || $originalPayment->status !== 'verified'
                    || !is_array($originalNotes)
                    || ($originalNotes['purpose'] ?? null) !== 'course_cart'
                    || (int) ($originalNotes['course_cart_order_id'] ?? 0) !== (int) $sourceId) {
                    throw new RuntimeException('Course-cart order is not bound to a verified Universal Payment transaction.');
                }

                User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
                $order = DB::table($sourceTable)
                    ->where('id', (int) $sourceId)
                    ->where('user_id', $buyer->id)
                    ->lockForUpdate()
                    ->first();
                $invoice = DB::table('fee_invoices')->where('id', $orderSnapshot->invoice_id)->lockForUpdate()->first();
            }

            if (!$order || !$invoice || (int) $order->invoice_id !== (int) $orderSnapshot->invoice_id) {
                throw new RuntimeException('Referral reversal source order or invoice changed during reconciliation.');
            }

            $orderCurrency = strtoupper(trim((string) $order->currency));
            if ($orderCurrency !== strtoupper(trim($currency))
                || !in_array((string) $invoice->status, ['paid', 'partially_refunded', 'refunded'], true)) {
                throw new RuntimeException('Referral reversal currency or invoice settlement state does not match the source order.');
            }

            $orderAmount = $sourceType === 'commerce_order'
                ? (float) $order->grand_total
                : (float) $order->net_amount;
            if (abs($orderAmount - (float) $invoice->net_amount) > 0.01
                || strtoupper(trim((string) $invoice->currency)) !== $orderCurrency) {
                throw new RuntimeException('Referral reversal source order does not match its authoritative invoice amount and currency.');
            }

            if ($sourceType === 'commerce_order') {
                if (!in_array((string) $order->status, ['completed', 'paid', 'refunded'], true)
                    || (Schema::hasColumn($sourceTable, 'payment_status')
                        && !in_array((string) $order->payment_status, ['paid', 'settled', 'partially_refunded', 'refunded'], true))) {
                    throw new RuntimeException('Referral reversal commerce order has no verified paid state.');
                }
            } elseif (!in_array((string) $order->status, ['paid', 'refunded'], true)) {
                throw new RuntimeException('Referral reversal course-cart order has no verified paid state.');
            }

            $refundTransaction = DB::table('payment_transactions')
                ->where('id', (int) $refundRecord->payment_transaction_id)
                ->lockForUpdate()
                ->first();
            if (!$refundTransaction
                || (int) $refundTransaction->invoice_id !== (int) $order->invoice_id
                || strtoupper(trim((string) $refundTransaction->currency)) !== $orderCurrency
                || !in_array((string) $refundTransaction->status, ['settled', 'refunded'], true)
                || (float) $refundTransaction->amount + 0.01 < (float) $refundRecord->amount
                || trim((string) $refundRecord->reason) === '') {
                throw new RuntimeException('Recorded payment refund does not belong to the source order invoice or is not settled.');
            }

            $gateway = strtolower(trim((string) $refundTransaction->gateway_slug));
            $providerGateway = $gateway;
            $providerReference = null;
            if ($gateway === 'offline') {
                $approver = empty($refundRecord->approved_by)
                    ? null
                    : User::query()->whereKey((int) $refundRecord->approved_by)->first();
                if (!$approver || !$approver->hasRole('admin')) {
                    throw new RuntimeException('Offline refund requires an admin-approved refund record before commission reversal.');
                }
            } else {
                $providerReference = trim((string) ($refundRecord->provider_refund_reference ?? ''));
                if (!Schema::hasColumn('payment_refunds', 'provider_refund_reference') || $providerReference === '') {
                    throw new RuntimeException('Provider refund reference is required before commission reversal.');
                }
                if (!Schema::hasColumn('referral_commission_reversals', 'provider_gateway')
                    || !Schema::hasColumn('referral_commission_reversals', 'provider_refund_reference')) {
                    throw new RuntimeException('Provider refund identity migration is required before commission reversal.');
                }

                $existingProviderRefund = DB::table('referral_commission_reversals')
                    ->where('provider_gateway', $providerGateway)
                    ->where('provider_refund_reference', $providerReference)
                    ->lockForUpdate()
                    ->first();
                if ($existingProviderRefund) {
                    if ((int) $existingProviderRefund->payment_refund_id === $paymentRefundId) {
                        return (int) $existingProviderRefund->id;
                    }
                    throw new RuntimeException('Provider refund reference has already been applied to another refund record.');
                }
            }

            if (!Schema::hasColumn('referral_commission_reversals', 'provider_gateway')
                || !Schema::hasColumn('referral_commission_reversals', 'provider_refund_reference')) {
                throw new RuntimeException('Provider refund identity migration is required before commission reversal.');
            }

            $existing = DB::table('referral_commission_reversals')
                ->where('payment_refund_id', $paymentRefundId)->lockForUpdate()->first();
            if ($existing) {
                return (int) $existing->id;
            }

            $commission = DB::table('referral_commissions')
                ->where('referred_user_id', $buyer->id)
                ->where('source_type', $sourceType)
                ->where('source_id', (string) $sourceId)
                ->where('currency', strtoupper($currency))
                ->lockForUpdate()->first();

            if (!$commission) {
                return null;
            }

            $alreadyReversed = (float) DB::table('referral_commission_reversals')
                ->where('referral_commission_id', $commission->id)
                ->sum('refunded_amount');
            $refund = round($refundedAmount, 2);
            $eligible = round((float) $commission->eligible_amount, 2);
            if ($refund > round($eligible - $alreadyReversed, 2) + 0.01) {
                throw new RuntimeException('Referral commission reversal exceeds the remaining eligible purchase amount.');
            }

            $alreadyReversedCommission = (float) DB::table('referral_commission_reversals')
                ->where('referral_commission_id', $commission->id)
                ->sum('commission_reversal_amount');
            $originalCommission = round($eligible * ((float) $commission->commission_percent / 100), 2);
            $cumulativeRefunded = round($alreadyReversed + $refund, 2);
            $cumulativeTarget = $cumulativeRefunded >= $eligible - 0.01
                ? $originalCommission
                : round($cumulativeRefunded * ((float) $commission->commission_percent / 100), 2);
            $reversalAmount = min(
                max(0, round($originalCommission - $alreadyReversedCommission, 2)),
                max(0, round($cumulativeTarget - $alreadyReversedCommission, 2)),
            );
            $reversalAmount = round($reversalAmount, 2);
            $requiresRecovery = $commission->status === 'paid'
                || $commission->status === 'payout_requested'
                || $commission->payout_request_id !== null;

            $reversalId = DB::table('referral_commission_reversals')->insertGetId([
                'referral_commission_id' => $commission->id,
                'payment_refund_id' => $paymentRefundId,
                'provider_gateway' => $providerGateway,
                'provider_refund_reference' => $providerReference,
                'refunded_amount' => $refund,
                'commission_reversal_amount' => $reversalAmount,
                'currency' => strtoupper($currency),
                'status' => $requiresRecovery ? 'recovery_due' : 'applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!$requiresRecovery) {
                $remainingCommission = max(0, round((float) $commission->commission_amount - $reversalAmount, 2));
                DB::table('referral_commissions')->where('id', $commission->id)->update([
                    'commission_amount' => $remainingCommission,
                    'status' => $remainingCommission <= 0 ? 'reversed' : $commission->status,
                    'updated_at' => now(),
                ]);
            }

            return (int) $reversalId;
        });
    }

    public function requestPayout(User $owner, string $method, string $accountDetails): int
    {
        $type = $this->ownerType($owner);
        if (!in_array($method, ['bkash', 'nagad', 'bank'], true) || strlen(trim($accountDetails)) < 6) {
            throw ValidationException::withMessages(['payout' => 'Provide a valid bKash, Nagad or bank payout destination.']);
        }

        return DB::transaction(function () use ($owner, $type, $method, $accountDetails): int {
            // Local bKash, Nagad and bank payouts are denominated in BDT.
            // Never combine foreign-currency commissions or silently treat them as BDT.
            $commissions = DB::table('referral_commissions')->where('referrer_user_id', $owner->id)
                ->where('status', 'approved')->where('currency', 'BDT')->orderBy('id')->lockForUpdate()->get();
            if (Schema::hasTable('referral_commission_reversals')
                && DB::table('referral_commission_reversals as reversals')
                    ->join('referral_commissions as commissions', 'commissions.id', '=', 'reversals.referral_commission_id')
                    ->where('commissions.referrer_user_id', $owner->id)
                    ->where('commissions.currency', 'BDT')
                    ->where('reversals.status', 'recovery_due')
                    ->lockForUpdate()
                    ->exists()) {
                throw ValidationException::withMessages([
                    'payout' => 'A refunded commission is awaiting recovery reconciliation and cannot be paid again yet.',
                ]);
            }

            $amount = round((float) $commissions->sum('commission_amount'), 2);
            $minimum = (float) (DB::table('referral_income_rules')->where('referrer_type', $type)->value('minimum_payout') ?? 500);
            if ($amount < $minimum) {
                throw ValidationException::withMessages(['payout' => 'Your approved BDT balance has not reached the minimum payout amount. Foreign-currency commissions are kept separate and cannot be paid through local BDT methods.']);
            }

            $payoutId = DB::table('referral_payout_requests')->insertGetId([
                'user_id' => $owner->id, 'owner_type' => $type, 'amount' => $amount, 'currency' => 'BDT',
                'method' => $method, 'account_details_encrypted' => Crypt::encryptString(trim($accountDetails)),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('referral_commissions')->whereIn('id', $commissions->pluck('id'))
                ->update(['status' => 'payout_requested', 'payout_request_id' => $payoutId, 'updated_at' => now()]);
            return $payoutId;
        });
    }

    private function ownerType(User $user): string
    {
        if ($user->hasRole('student')) return 'student';
        if ($user->hasRole('teacher')) return 'teacher';
        throw ValidationException::withMessages(['referral' => 'Only student and teacher accounts can participate in referrals.']);
    }
}