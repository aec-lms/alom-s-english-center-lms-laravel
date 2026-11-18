<?php

declare(strict_types=1);

namespace Plugins\Modules\MarketingManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Models\SystemSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Plugins\Modules\MarketingManagement\Models\MarketingCampaign;
use Plugins\Modules\MarketingManagement\Models\PromoCoupon;

class MarketingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->ensureCentralAdmin($request->user());
        $campaigns = Schema::hasTable('marketing_campaigns')
            ? MarketingCampaign::query()->orderByDesc('id')->get()
            : collect();

        $coupons = Schema::hasTable('promo_coupons')
            ? PromoCoupon::query()->orderByDesc('id')->get()
            : collect();

        $courses = Schema::hasTable('courses')
            ? DB::table('courses')->where('status', 'published')->orderBy('title')->get(['id', 'title'])
            : collect();
        $commerceSetting = SystemSetting::query()->where('key', 'course_commerce.gift_vouchers')->first();
        $giftVoucherSettings = array_merge([
            'enabled' => true,
            'minimum_amount' => 100,
            'maximum_amount' => 100000,
            'validity_days' => 180,
            'allow_personal_message' => true,
        ], is_array($commerceSetting?->value) ? $commerceSetting->value : (json_decode((string) $commerceSetting?->value, true) ?: []));
        $giftVouchers = Schema::hasTable('course_gift_vouchers')
            ? DB::table('course_gift_vouchers')
                ->leftJoin('users as senders', 'senders.id', '=', 'course_gift_vouchers.sender_user_id')
                ->orderByDesc('course_gift_vouchers.id')
                ->limit(100)
                ->get([
                    'course_gift_vouchers.id', 'course_gift_vouchers.recipient_email',
                    'course_gift_vouchers.recipient_name', 'course_gift_vouchers.currency',
                    'course_gift_vouchers.amount', 'course_gift_vouchers.remaining_amount',
                    'course_gift_vouchers.status', 'course_gift_vouchers.expires_at',
                    'course_gift_vouchers.paid_at', 'course_gift_vouchers.redeemed_at',
                    'course_gift_vouchers.recipient_user_id', 'senders.name as sender_name',
                ])
            : collect();

        return view('marketing-management::index', compact('campaigns', 'coupons', 'courses', 'giftVoucherSettings', 'giftVouchers'));
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $this->ensureCentralAdmin($request->user());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', 'string', 'max:60'],
            'target_course' => ['nullable', 'string', 'max:160'],
            'budget_bdt' => ['required', 'numeric', 'min:0'],
            'utm_code' => ['nullable', 'string', 'max:80'],
        ]);

        $campaign = MarketingCampaign::query()->create($validated + ['status' => 'active']);
        $this->audit->log('marketing.campaign_created', 'marketing_campaign', (string) $campaign->id);

        return back()->with('status', "Campaign '{$campaign->name}' launched.");
    }

    public function storeCoupon(Request $request): RedirectResponse
    {
        $this->ensureCentralAdmin($request->user());
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:promo_coupons,code'],
            'discount_type' => ['sometimes', 'in:percent,fixed'],
            'discount_percent' => ['nullable', 'required_if:discount_type,percent', 'integer', 'min:1', 'max:100'],
            'discount_amount' => ['nullable', 'required_if:discount_type,fixed', 'numeric', 'min:0.01', 'max:100000000'],
            'max_uses' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'minimum_subtotal' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'applies_to_course_ids' => ['nullable', 'array', 'max:100'],
            'applies_to_course_ids.*' => ['required', 'integer', 'distinct', 'exists:courses,id'],
            'first_purchase_only' => ['nullable', 'boolean'],
        ]);

        $coupon = PromoCoupon::query()->create([
            'code' => strtoupper(trim($validated['code'])),
            'discount_type' => $validated['discount_type'] ?? 'percent',
            'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
            'discount_amount' => $validated['discount_amount'] ?? null,
            'max_uses' => (int) ($validated['max_uses'] ?? 0),
            'used_count' => 0,
            'minimum_subtotal' => $validated['minimum_subtotal'] ?? 0,
            'maximum_discount' => $validated['maximum_discount'] ?? null,
            'per_user_limit' => $validated['per_user_limit'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'applies_to_course_ids' => array_values(array_map('intval', $validated['applies_to_course_ids'] ?? [])),
            'first_purchase_only' => $request->boolean('first_purchase_only'),
            'is_active' => true,
        ]);

        $this->audit->log('marketing.coupon_created', 'promo_coupon', (string) $coupon->id, ['code' => $coupon->code]);

        $discountLabel = $coupon->discount_type === 'fixed'
            ? number_format((float) $coupon->discount_amount, 2) . ' BDT OFF'
            : $coupon->discount_percent . '% OFF';

        return back()->with('status', "Promo coupon '{$coupon->code}' ({$discountLabel}) created.");
    }
    public function updateCoupon(Request $request, int $couponId): RedirectResponse
    {
        $this->ensureCentralAdmin($request->user());
        $validated = $request->validate([
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_percent' => ['nullable', 'required_if:discount_type,percent', 'integer', 'min:1', 'max:100'],
            'discount_amount' => ['nullable', 'required_if:discount_type,fixed', 'numeric', 'min:0.01', 'max:100000000'],
            'max_uses' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'minimum_subtotal' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'applies_to_course_ids' => ['nullable', 'array', 'max:100'],
            'applies_to_course_ids.*' => ['required', 'integer', 'distinct', 'exists:courses,id'],
            'first_purchase_only' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $couponCode = DB::transaction(function () use ($request, $couponId, $validated): string {
            $coupon = PromoCoupon::query()->lockForUpdate()->findOrFail($couponId);
            $coupon->update([
                'discount_type' => $validated['discount_type'],
                'discount_percent' => (int) ($validated['discount_percent'] ?? 0),
                'discount_amount' => $validated['discount_amount'] ?? null,
                'max_uses' => (int) ($validated['max_uses'] ?? 0),
                'minimum_subtotal' => $validated['minimum_subtotal'] ?? 0,
                'maximum_discount' => $validated['maximum_discount'] ?? null,
                'per_user_limit' => $validated['per_user_limit'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'applies_to_course_ids' => array_values(array_map('intval', $validated['applies_to_course_ids'] ?? [])),
                'first_purchase_only' => $request->boolean('first_purchase_only'),
                'is_active' => $request->boolean('is_active'),
            ]);
            $this->audit->log('marketing.coupon_updated', 'promo_coupon', (string) $coupon->id, [
                'code' => $coupon->code, 'is_active' => $coupon->is_active,
            ]);

            return (string) $coupon->code;
        }, 3);

        return back()->with('status', "Coupon '{$couponCode}' settings updated.");
    }

    public function updateGiftVoucherStatus(Request $request, int $voucherId): RedirectResponse
    {
        $this->ensureCentralAdmin($request->user());
        abort_unless(Schema::hasTable('course_gift_vouchers'), 404, 'Gift voucher storage is unavailable.');
        $validated = $request->validate(['status' => ['required', 'in:paid,suspended']]);

        DB::transaction(function () use ($request, $voucherId, $validated): void {
            $voucher = DB::table('course_gift_vouchers')->where('id', $voucherId)->lockForUpdate()->first();
            abort_unless($voucher, 404, 'Gift voucher not found.');
            abort_unless(in_array($voucher->status, ['paid', 'suspended'], true), 409,
                'Only paid, unclaimed gift vouchers can be suspended or reactivated.');
            abort_unless($voucher->recipient_user_id === null, 409, 'A claimed gift voucher cannot be changed.');
            if ($validated['status'] === 'paid') {
                abort_if($voucher->expires_at && now()->greaterThan($voucher->expires_at), 410,
                    'An expired gift voucher cannot be reactivated.');
            }
            DB::table('course_gift_vouchers')->where('id', $voucherId)->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);
            $this->audit->log('marketing.gift_voucher_status_updated', 'course_gift_voucher', (string) $voucherId, [
                'from' => $voucher->status,
                'to' => $validated['status'],
            ]);
        }, 3);

        return back()->with('status', 'Gift voucher status updated.');
    }

    public function updateGiftVoucherSettings(Request $request): RedirectResponse
    {
        $this->ensureCentralAdmin($request->user());
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'minimum_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'maximum_amount' => ['required', 'numeric', 'gte:minimum_amount', 'max:10000000'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'allow_personal_message' => ['nullable', 'boolean'],
        ]);
        $settings = [
            'enabled' => $request->boolean('enabled'),
            'minimum_amount' => round((float) $validated['minimum_amount'], 2),
            'maximum_amount' => round((float) $validated['maximum_amount'], 2),
            'validity_days' => (int) $validated['validity_days'],
            'allow_personal_message' => $request->boolean('allow_personal_message'),
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => 'course_commerce.gift_vouchers'],
            ['value' => json_encode($settings, JSON_THROW_ON_ERROR), 'is_encrypted' => false, 'updated_by' => $request->user()->id]
        );
        $this->audit->log('marketing.gift_voucher_settings_updated', 'system_setting', 'course_commerce.gift_vouchers');

        return back()->with('status', 'Gift voucher settings updated.');
    }

    private function ensureCentralAdmin($actor): void
    {
        abort_unless($actor && $actor->hasRole('admin'), 403, 'Only a central administrator can manage global marketing campaigns and coupons.');
    }

}
