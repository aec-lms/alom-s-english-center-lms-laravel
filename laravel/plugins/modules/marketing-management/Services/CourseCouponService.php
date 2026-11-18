<?php

declare(strict_types=1);

namespace Plugins\\Modules\\MarketingManagement\\Services;

use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\ValidationException;
use Plugins\\Modules\\MarketingManagement\\Models\\PromoCoupon;

class CourseCouponService
{
    public function quote(string $code, int $userId, array $courseIds, float $subtotal): array
    {
        $coupon = PromoCoupon::query()->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
        if (!$coupon || !$coupon->is_active) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is invalid or inactive.']);
        }
        $this->assertEligible($coupon, $userId, $courseIds, $subtotal);
        return ['coupon_id' => (int) $coupon->id, 'code' => $coupon->code, 'subtotal' => round($subtotal, 2),
            'discount' => $this->discount($coupon, $subtotal), 'total' => round(max(0, $subtotal - $this->discount($coupon, $subtotal)), 2)];
    }

    public function reserve(string $code, int $userId, array $courseIds, float $subtotal): array
    {
        return DB::transaction(function () use ($code, $userId, $courseIds, $subtotal): array {
            $coupon = PromoCoupon::query()->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->lockForUpdate()->first();
            if (!$coupon || !$coupon->is_active) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon is invalid or inactive.']);
            }
            $this->assertEligible($coupon, $userId, $courseIds, $subtotal);
            $activeReservations = DB::table('promo_coupon_redemptions')->where('coupon_id', $coupon->id)
                ->where('status', 'reserved')->where(function ($q): void {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->count();
            if ((int) $coupon->used_count + $activeReservations >= (int) $coupon->max_uses) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon has reached its usage limit.']);
            }
            $perUserLimit = $coupon->per_user_limit;
            if ($perUserLimit !== null) {
                $userUses = DB::table('promo_coupon_redemptions')->where('coupon_id', $coupon->id)
                    ->where('user_id', $userId)->whereIn('status', ['reserved', 'redeemed'])
                    ->where(function ($q): void {
                        $q->where('status', 'redeemed')->orWhereNull('expires_at')->orWhere('expires_at', '>', now());
                    })->count();
                if ($userUses >= (int) $perUserLimit) {
                    throw ValidationException::withMessages(['coupon_code' => 'You have reached this coupon usage limit.']);
                }
            }
            $discount = $this->discount($coupon, $subtotal);
            $key = (string) Str::uuid();
            DB::table('promo_coupon_redemptions')->insert([
                'coupon_id' => $coupon->id, 'user_id' => $userId, 'reservation_key' => $key,
                'status' => 'reserved', 'subtotal' => round($subtotal, 2), 'discount_amount' => $discount,
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['reservation_key' => $key, 'coupon_id' => (int) $coupon->id, 'code' => $coupon->code,
                'subtotal' => round($subtotal, 2), 'discount' => $discount, 'total' => round(max(0, $subtotal - $discount), 2)];
        }, 3);
    }

    public function redeem(string $reservationKey): void
    {
        DB::transaction(function () use ($reservationKey): void {
            $reservation = DB::table('promo_coupon_redemptions')->where('reservation_key', $reservationKey)->lockForUpdate()->first();
            if (!$reservation || $reservation->status !== 'reserved' || ($reservation->expires_at && now()->greaterThan($reservation->expires_at))) {
                throw ValidationException::withMessages(['coupon_code' => 'Coupon reservation is no longer valid.']);
            }
            $coupon = PromoCoupon::query()->lockForUpdate()->findOrFail($reservation->coupon_id);
            if ((int) $coupon->used_count >= (int) $coupon->max_uses) {
                throw ValidationException::withMessages(['coupon_code' => 'Coupon usage limit was reached.']);
            }
            $coupon->increment('used_count');
            DB::table('promo_coupon_redemptions')->where('id', $reservation->id)->update([
                'status' => 'redeemed', 'redeemed_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function release(string $reservationKey): void
    {
        DB::table('promo_coupon_redemptions')->where('reservation_key', $reservationKey)
            ->where('status', 'reserved')->update(['status' => 'released', 'updated_at' => now()]);
    }

    private function assertEligible(PromoCoupon $coupon, int $userId, array $courseIds, float $subtotal): void
    {
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not active yet.']);
        }
        if ($coupon->expires_at && now()->toDateString() > $coupon->expires_at->toDateString()) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon has expired.']);
        }
        if ($subtotal < (float) ($coupon->minimum_subtotal ?? 0)) {
            throw ValidationException::withMessages(['coupon_code' => 'The cart does not meet the minimum purchase amount.']);
        }
        $allowed = $coupon->applies_to_course_ids;
        if (is_array($allowed) && $allowed !== [] && array_diff($courseIds, array_map('intval', $allowed)) !== []) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon does not apply to every course in your cart.']);
        }
        if ($coupon->first_purchase_only && DB::table('enrollments')->where('user_id', $userId)->whereIn('status', ['active', 'completed'])->exists()) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon is available only for a first purchase.']);
        }
    }

    private function discount(PromoCoupon $coupon, float $subtotal): float
    {
        $type = $coupon->discount_type ?? 'percent';
        $raw = $type === 'fixed'
            ? (float) ($coupon->discount_amount ?? 0)
            : $subtotal * ((float) $coupon->discount_percent / 100);
        if ($coupon->maximum_discount !== null) $raw = min($raw, (float) $coupon->maximum_discount);
        return round(min($subtotal, max(0, $raw)), 2);
    }
}
