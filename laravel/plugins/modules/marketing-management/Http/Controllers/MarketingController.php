<?php

declare(strict_types=1);

namespace Plugins\Modules\MarketingManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\MarketingManagement\Models\MarketingCampaign;
use Plugins\Modules\MarketingManagement\Models\PromoCoupon;

class MarketingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $campaigns = Schema::hasTable('marketing_campaigns')
            ? MarketingCampaign::query()->orderByDesc('id')->get()
            : collect();

        $coupons = Schema::hasTable('promo_coupons')
            ? PromoCoupon::query()->orderByDesc('id')->get()
            : collect();

        return view('marketing-management::index', compact('campaigns', 'coupons'));
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
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
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:promo_coupons,code'],
            'discount_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'max_uses' => ['required', 'integer', 'min:1', 'max:100000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $coupon = PromoCoupon::query()->create([
            'code' => strtoupper(trim($validated['code'])),
            'discount_percent' => $validated['discount_percent'],
            'max_uses' => $validated['max_uses'],
            'used_count' => 0,
            'expires_at' => $validated['expires_at'] ?? null,
            'is_active' => true,
        ]);

        $this->audit->log('marketing.coupon_created', 'promo_coupon', (string) $coupon->id, ['code' => $coupon->code]);

        return back()->with('status', "Promo coupon '{$coupon->code}' ({$coupon->discount_percent}% OFF) created.");
    }
}
