<?php

declare(strict_types=1);

namespace Plugins\Modules\MarketingManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
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

        return view('marketing-management::index', compact('campaigns', 'coupons', 'courses'));
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

        return back()->with('status', "Promo coupon '{$coupon->code}' ({$coupon->discount_percent}% OFF) created.");
    }
    private function ensureCentralAdmin($actor): void
    {
        abort_unless($actor && $actor->hasRole('admin'), 403, 'Only a central administrator can manage global marketing campaigns and coupons.');
    }

}
