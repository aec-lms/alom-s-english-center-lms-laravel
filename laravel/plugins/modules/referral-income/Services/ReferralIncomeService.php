<?php

declare(strict_types=1);

namespace Plugins\Modules\ReferralIncome\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
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
        $record = DB::table('referral_codes')->where('code', $code)->where('is_active', true)->first();
        if (!$record) {
            throw ValidationException::withMessages(['referral_code' => 'Referral code is invalid or inactive.']);
        }
        if ((int) $record->owner_user_id === (int) $referred->id) {
            throw ValidationException::withMessages(['referral_code' => 'You cannot use your own referral code.']);
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
    public function recordSettledPurchase(User $buyer, string $sourceType, string|int $sourceId, float $eligibleAmount, string $currency = 'BDT'): ?int
    {
        if ($eligibleAmount <= 0 || strlen((string) $sourceId) > 80 || !preg_match('/^[a-z0-9_-]{2,40}$/i', $sourceType)) {
            return null;
        }

        return DB::transaction(function () use ($buyer, $sourceType, $sourceId, $eligibleAmount, $currency): ?int {
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

    public function requestPayout(User $owner, string $method, string $accountDetails): int
    {
        $type = $this->ownerType($owner);
        if (!in_array($method, ['bkash', 'nagad', 'bank'], true) || strlen(trim($accountDetails)) < 6) {
            throw ValidationException::withMessages(['payout' => 'Provide a valid bKash, Nagad or bank payout destination.']);
        }

        return DB::transaction(function () use ($owner, $type, $method, $accountDetails): int {
            $commissions = DB::table('referral_commissions')->where('referrer_user_id', $owner->id)
                ->where('status', 'approved')->orderBy('id')->lockForUpdate()->get();
            $amount = round((float) $commissions->sum('commission_amount'), 2);
            $minimum = (float) (DB::table('referral_income_rules')->where('referrer_type', $type)->value('minimum_payout') ?? 500);
            if ($amount < $minimum) {
                throw ValidationException::withMessages(['payout' => 'Your approved balance has not reached the minimum payout amount.']);
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