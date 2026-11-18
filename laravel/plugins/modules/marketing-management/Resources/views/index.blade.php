@extends('layouts.admin')
@section('title', 'Marketing & Coupons — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Marketing &amp; Promotional Coupons</h1>
        <p class="text-xs text-slate-500 mt-0.5">Launch enrollment growth campaigns and manage discount promo codes.</p>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create Campaign</h2>
            <form method="POST" action="{{ route('plugins.marketing.campaigns.store') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="name" required placeholder="Campaign Name *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="channel" required value="Facebook & Google" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="target_course" placeholder="Target Course (e.g. IELTS Masterclass)" class="rounded-lg border border-slate-300 px-3 py-2">
                <div class="grid grid-cols-2 gap-3">
                    <input name="budget_bdt" type="number" step="0.01" min="0" value="5000" required class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="utm_code" placeholder="UTM Campaign Tag" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Launch Campaign</button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Create Advanced Course Coupon</h2>
            <form method="POST" action="{{ route('plugins.marketing.coupons.store') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="code" required maxlength="40" placeholder="Coupon Code (e.g. IELTS20) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono uppercase">
                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1 text-slate-600">Discount type
                        <select name="discount_type" required class="rounded-lg border border-slate-300 px-3 py-2">
                            <option value="percent">Percentage</option><option value="fixed">Fixed amount</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-slate-600">Percent discount
                        <input name="discount_percent" type="number" min="1" max="100" value="15" placeholder="15" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Fixed discount (BDT)
                        <input name="discount_amount" type="number" min="0.01" step="0.01" placeholder="e.g. 250" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Maximum uses (0 = unlimited)
                        <input name="max_uses" type="number" min="0" value="100" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Minimum cart subtotal (BDT)
                        <input name="minimum_subtotal" type="number" min="0" step="0.01" value="0" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Maximum discount (BDT)
                        <input name="maximum_discount" type="number" min="0.01" step="0.01" placeholder="Optional cap" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Per-student usage limit
                        <input name="per_user_limit" type="number" min="1" placeholder="Optional" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Starts at
                        <input name="starts_at" type="datetime-local" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Expires at
                        <input name="expires_at" type="date" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                </div>
                <label class="grid gap-1 text-slate-600">Limit to courses (leave empty for all courses)
                    <select name="applies_to_course_ids[]" multiple size="5" class="rounded-lg border border-slate-300 px-3 py-2">
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->title }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2 text-slate-700"><input type="checkbox" name="first_purchase_only" value="1" class="rounded border-slate-300"> First purchase only</label>
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white font-bold">Create Coupon</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Active Promo Coupons ({{ $coupons->count() }})</h2>
        @forelse ($coupons as $coupon)
            <div class="border border-slate-200 rounded-xl p-3 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold font-mono text-sm text-slate-900">{{ $coupon->code }} &mdash; {{ $coupon->discount_type === 'fixed' ? number_format((float) $coupon->discount_amount, 2) . ' BDT OFF' : $coupon->discount_percent . '% OFF' }}</div>
                    <div class="text-slate-500">Used {{ $coupon->used_count }} / {{ (int) $coupon->max_uses > 0 ? $coupon->max_uses : 'Unlimited' }} &middot; Per student {{ $coupon->per_user_limit ?? 'Unlimited' }} &middot; Expires {{ $coupon->expires_at?->toDateString() ?? 'No expiry' }}</div>
                </div>
                <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 font-bold text-[11px]">{{ $coupon->is_active ? 'Active' : 'Disabled' }}</span>
            </div>
        @empty
            <p class="text-slate-400">No promo coupons created yet.</p>
        @endforelse
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div>
            <h2 class="text-sm font-extrabold text-slate-900">Admin Gift Voucher Controls</h2>
            <p class="mt-1 text-xs text-slate-500">Control whether students can buy vouchers, allowed amounts, expiry and personal messages. Existing paid vouchers keep their original terms.</p>
        </div>
        <form method="POST" action="{{ route('plugins.marketing.gift-voucher-settings.update') }}" class="grid gap-3 md:grid-cols-2 text-xs">
            @csrf
            @method('PUT')
            <label class="flex items-center gap-2 font-bold text-slate-700 md:col-span-2">
                <input type="checkbox" name="enabled" value="1" @checked($giftVoucherSettings['enabled']) class="rounded border-slate-300">
                Enable new gift voucher purchases
            </label>
            <label class="grid gap-1 text-slate-600">Minimum voucher amount (BDT)
                <input type="number" name="minimum_amount" min="1" step="0.01" required value="{{ $giftVoucherSettings['minimum_amount'] }}" class="rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="grid gap-1 text-slate-600">Maximum voucher amount (BDT)
                <input type="number" name="maximum_amount" min="1" step="0.01" required value="{{ $giftVoucherSettings['maximum_amount'] }}" class="rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="grid gap-1 text-slate-600">Voucher validity (days)
                <input type="number" name="validity_days" min="1" max="1095" required value="{{ $giftVoucherSettings['validity_days'] }}" class="rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="flex items-center gap-2 text-slate-700 self-end pb-2">
                <input type="checkbox" name="allow_personal_message" value="1" @checked($giftVoucherSettings['allow_personal_message']) class="rounded border-slate-300">
                Allow sender personal message
            </label>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2.5 font-bold text-white md:col-span-2">Save Gift Voucher Settings</button>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div>
            <h2 class="text-sm font-extrabold text-slate-900">Manage Existing Coupons</h2>
            <p class="mt-1 text-xs text-slate-500">Update discount rules, course targeting, usage limits, dates and enable/disable status. Coupons with redemption history are retained, not deleted.</p>
        </div>
        @forelse ($coupons as $coupon)
            <form method="POST" action="{{ route('plugins.marketing.coupons.update', $coupon->id) }}" class="rounded-xl border border-slate-200 p-4 space-y-3">
                @csrf
                @method('PATCH')
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-mono text-sm font-black text-slate-900">{{ $coupon->code }}</div>
                    <label class="flex items-center gap-2 text-xs font-bold text-slate-700">
                        <input type="checkbox" name="is_active" value="1" @checked($coupon->is_active) class="rounded border-slate-300">
                        Enabled for new checkouts
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                    <label class="grid gap-1 text-slate-600">Discount type
                        <select name="discount_type" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option value="percent" @selected($coupon->discount_type !== 'fixed')>Percentage</option>
                            <option value="fixed" @selected($coupon->discount_type === 'fixed')>Fixed amount</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-slate-600">Percent (1–100)
                        <input name="discount_percent" type="number" min="1" max="100" value="{{ $coupon->discount_percent ?: 10 }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Fixed amount (BDT)
                        <input name="discount_amount" type="number" min="0.01" step="0.01" value="{{ $coupon->discount_amount }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Max uses (0 = unlimited)
                        <input name="max_uses" type="number" min="0" value="{{ $coupon->max_uses }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Minimum subtotal
                        <input name="minimum_subtotal" type="number" min="0" step="0.01" value="{{ $coupon->minimum_subtotal ?? 0 }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Maximum discount
                        <input name="maximum_discount" type="number" min="0.01" step="0.01" value="{{ $coupon->maximum_discount }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Per-student limit
                        <input name="per_user_limit" type="number" min="1" value="{{ $coupon->per_user_limit }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Starts at
                        <input name="starts_at" type="datetime-local" value="{{ $coupon->starts_at?->format('Y-m-d\\TH:i') }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="grid gap-1 text-slate-600">Expires at
                        <input name="expires_at" type="date" value="{{ $coupon->expires_at?->format('Y-m-d') }}" class="rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                </div>
                <label class="block text-xs text-slate-600">Applicable courses (empty = all)
                    <select name="applies_to_course_ids[]" multiple size="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}" @selected(in_array((int) $course->id, array_map('intval', $coupon->applies_to_course_ids ?? []), true))>{{ $course->title }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="first_purchase_only" value="1" @checked($coupon->first_purchase_only) class="rounded border-slate-300">
                    First purchase only
                </label>
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[11px] text-slate-500">Redeemed {{ $coupon->used_count }} times. Existing paid orders are not changed.</p>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white">Save Coupon</button>
                </div>
            </form>
        @empty
            <p class="text-xs text-slate-400">No coupons are available to manage.</p>
        @endforelse
    </section>
</div>
@endsection
