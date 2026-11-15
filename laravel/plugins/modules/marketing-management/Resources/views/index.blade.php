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
            <h2 class="text-sm font-bold text-slate-900">Create Promo Coupon</h2>
            <form method="POST" action="{{ route('plugins.marketing.coupons.store') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="code" required placeholder="Coupon Code (e.g. IELTS20) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono uppercase">
                <div class="grid grid-cols-2 gap-3">
                    <input name="discount_percent" type="number" min="1" max="100" value="15" required placeholder="Discount %" class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="max_uses" type="number" min="1" value="100" required placeholder="Max Uses" class="rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <input name="expires_at" type="date" class="rounded-lg border border-slate-300 px-3 py-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white font-bold">Create Coupon</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Active Promo Coupons ({{ $coupons->count() }})</h2>
        @forelse ($coupons as $coupon)
            <div class="border border-slate-200 rounded-xl p-3 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold font-mono text-sm text-slate-900">{{ $coupon->code }} &mdash; {{ $coupon->discount_percent }}% OFF</div>
                    <div class="text-slate-500">Used {{ $coupon->used_count }} / {{ $coupon->max_uses }} &middot; Expires {{ $coupon->expires_at?->toDateString() ?? 'No expiry' }}</div>
                </div>
                <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 font-bold text-[11px]">{{ $coupon->is_active ? 'Active' : 'Disabled' }}</span>
            </div>
        @empty
            <p class="text-slate-400">No promo coupons created yet.</p>
        @endforelse
    </div>
</div>
@endsection
