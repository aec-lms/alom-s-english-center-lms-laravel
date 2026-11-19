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
        $this->ownerType($referred);
        $code = strtoupper(trim($code));
        $record = DB::table('referral_codes')->where('code', $code)->where('is_active', true)->first();
        if (!$record) {
            throw ValidationException::withMessages(['referral_code' => 'Referral code is invalid or inactive.']);
        }
        if ((int) $record->owner_user_id === (int) $referred->id) {
            throw ValidationException::withMessages(['referral_code' => 'You cannot use your own referral code.']);
        }

        if (Schema::hasTable('commerce_orders') && DB::table('commerce_orders')->where('user_id', $referred->id)->whereIn('status', ['paid', 'settled', 'completed'])->exists()) {
            throw ValidationException::withMessages(['referral_code' => 'Referral codes must be applied before the first paid order.']);
        }

        DB::transaction(function () use ($referred, $record): void {
            $existing = DB::table('referral_attributions')->where('referred_user_id', $referred->id)->lockForUpdate()->exists();
            if ($existing) {
                throw ValidationException::withMessages(['referral_code' => 'A referral has already been assigned to this account.']);
            }
            DB::table('referral_attributions')->insert([
                'referrer_user_id' => $record->owner_user_id,
                'referred_user_id' => $referred->id,
                'referral_code_id' => $record->id,
                'referrer_type' => $record->owner_type,
                'status' => 'active',
                'attributed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Call only after a payment/order has been authoritatively verified as settled.
     * The unique source key makes webhook retries idempotent.
     */
    public function recordSettledPurchase(User $buyer, string $sourceType, string|int $sourceId, float $eligibleAmount, string $currency = 'BDT', ?int $paymentInvoiceId = null): ?int
    {
        if ($eligibleAmount <= 0 || strlen((string) $sourceId) > 80 || !preg_match('/^[a-z0-9_-]{2,40}$/i', $sourceType)) {
            return null;
        }

        return DB::transaction(function () use ($buyer, $sourceType, $sourceId, $eligibleAmount, $currency, $paymentInvoiceId): ?int {
            $attribution = DB::table('referral_attributions')
                ->where('referred_user_id', $buyer->id)->where('status', 'active')->lockForUpdate()->first();
            if (!$attribution) return null;

            $rule = DB::table('referral_income_rules')->where('referrer_type', $attribution->referrer_type)
                ->where('is_active', true)->lockForUpdate()->first();
            if (!$rule || (float) $rule->commission_percent <= 0) return null;

            $commission = round($eligibleAmount * ((float) $rule->commission_percent / 100), 2);
            if ($commission <= 0) return null;

            $inserted = DB::table('referral_commissions')->insertOrIgnore([
                'attribution_id' => $attribution->id,
                'referrer_user_id' => $attribution->referrer_user_id,
                'referred_user_id' => $buyer->id,
                'source_type' => $sourceType,
                'source_id' => (string) $sourceId,
                'payment_invoice_id' => $paymentInvoiceId,
                'eligible_amount' => round($eligibleAmount, 2),
                'commission_percent' => $rule->commission_percent,
                'commission_amount' => $commission,
                'currency' => strtoupper($currency),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!$inserted) {
                return DB::table('referral_commissions')->where('referred_user_id', $buyer->id)
                    ->where('source_type', $sourceType)->where('source_id', (string) $sourceId)->value('id');
            }

            return (int) DB::getPdo()->lastInsertId();
        });
    }


    /**
     * Apply a commission reversal only from a persisted refund that belongs to
     * the exact payment invoice used to create the commission.
     *
     * Refund IDs are idempotency keys. Unpaid commissions are reduced in place;
     * paid or payout-locked commissions create a recovery obligation without
     * mutating an already-issued payout.
     */
    public function reverseSettledPurchase(
        User $buyer,
        string $sourceType,
        string|int $sourceId,
        int $paymentRefundId,
        float $refundAmount,
        string $currency = 'BDT'
    ): int {
        $sourceId = (string) $sourceId;
        $currency = strtoupper(trim($currency));

        if ($paymentRefundId <= 0 || $refundAmount <= 0 || strlen($sourceId) > 80
            || !preg_match('/^[a-z0-9_-]{2,40}$/i', $sourceType)
            || !preg_match('/^[A-Z]{3,8}$/', $currency)) {
            throw ValidationException::withMessages(['refund' => 'Refund details are invalid.']);
        }

        return DB::transaction(function () use ($buyer, $sourceType, $sourceId, $paymentRefundId, $refundAmount, $currency): int {
            $existing = DB::table('referral_commission_reversals')
                ->where('payment_refund_id', $paymentRefundId)->first();
            if ($existing) {
                return (int) $existing->id;
            }

            if (!Schema::hasTable('payment_refunds') || !Schema::hasTable('payment_transactions')) {
                throw ValidationException::withMessages(['refund' => 'Verified payment refund records are unavailable.']);
            }

            $refund = DB::table('payment_refunds')->where('id', $paymentRefundId)->lockForUpdate()->first();
            if (!$refund || round((float) $refund->amount, 2) !== round($refundAmount, 2)) {
                throw ValidationException::withMessages(['refund' => 'The refund amount does not match the authoritative refund record.']);
            }

            $payment = DB::table('payment_transactions')->where('id', $refund->payment_transaction_id)->lockForUpdate()->first();
            if (!$payment || !in_array(strtolower((string) $payment->status), ['settled', 'refunded'], true)
                || strtoupper((string) $payment->currency) !== $currency
                || round((float) $payment->amount, 2) < $refundAmount) {
                throw ValidationException::withMessages(['refund' => 'The refund is not linked to a settled payment in the requested currency.']);
            }

            $commission = DB::table('referral_commissions')
                ->where('referred_user_id', $buyer->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()->first();

            if (!$commission || strtoupper((string) $commission->currency) !== $currency
                || empty($commission->payment_invoice_id)
                || (int) $commission->payment_invoice_id !== (int) $payment->invoice_id) {
                throw ValidationException::withMessages(['refund' => 'The refund does not belong to the invoice that generated this referral commission.']);
            }

            $transactionRefunded = round((float) DB::table('payment_refunds')
                ->where('payment_transaction_id', $payment->id)->sum('amount'), 2);
            if ($transactionRefunded > round((float) $payment->amount, 2) + 0.01) {
                throw ValidationException::withMessages(['refund' => 'Cumulative refunds exceed the original settled payment.']);
            }

            $priorRefunded = (float) DB::table('referral_commission_reversals')
                ->where('referral_commission_id', $commission->id)
                ->whereIn('status', ['applied', 'recovery_due'])
                ->sum('eligible_refund_amount');
            $remainingEligible = max(0, round((float) $commission->eligible_amount - $priorRefunded, 2));
            if ($refundAmount > $remainingEligible) {
                throw ValidationException::withMessages(['refund' => 'Cumulative refunds cannot exceed the eligible purchase amount.']);
            }

            $commissionReversal = round($refundAmount * ((float) $commission->commission_percent / 100), 2);
            $isPaidOrLocked = in_array((string) $commission->status, ['paid', 'payout_requested'], true);
            $reversalId = DB::table('referral_commission_reversals')->insertGetId([
                'referral_commission_id' => $commission->id,
                'payment_refund_id' => $paymentRefundId,
                'eligible_refund_amount' => round($refundAmount, 2),
                'commission_reversal_amount' => $commissionReversal,
                'currency' => $currency,
                'status' => $isPaidOrLocked ? 'recovery_due' : 'applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!$isPaidOrLocked) {
                $newEligibleAmount = max(0, round((float) $commission->eligible_amount - $refundAmount, 2));
                $newCommissionAmount = max(0, round((float) $commission->commission_amount - $commissionReversal, 2));
                DB::table('referral_commissions')->where('id', $commission->id)->update([
                    'eligible_amount' => $newEligibleAmount,
                    'commission_amount' => $newCommissionAmount,
                    'status' => $newCommissionAmount <= 0 ? 'reversed' : $commission->status,
                    'updated_at' => now(),
                ]);
            }

            return (int) $reversalId;
        }, 3);
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