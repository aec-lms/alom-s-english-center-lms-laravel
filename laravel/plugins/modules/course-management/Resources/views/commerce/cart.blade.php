@extends('layouts.app')

@section('title', 'Course Cart — AEC LMS')

@section('content')
<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
    <header class="rounded-3xl bg-gradient-to-br from-slate-950 via-blue-950 to-indigo-900 p-6 text-white shadow-xl sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[.2em] text-blue-200">Course Commerce</p>
        <h1 class="mt-2 text-3xl font-black">Your Course Cart</h1>
        <p class="mt-2 text-sm text-blue-100">Review your selected courses, apply an eligible coupon, and continue to secure checkout.</p>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if ($items->isEmpty())
        <section class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-3xl">🛒</div>
            <h2 class="mt-4 text-xl font-extrabold text-slate-900">Your cart is empty</h2>
            <p class="mt-2 text-sm text-slate-500">Explore our courses and add the ones you want to study.</p>
            <a href="{{ route('public.courses') }}" class="mt-5 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white hover:bg-blue-700">Browse courses</a>
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-[1fr_340px]">
            <section class="space-y-3">
                @foreach ($items as $item)
                    <article class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl">📘</div>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-extrabold text-slate-900">{{ $item->title }}</h2>
                            <p class="mt-1 text-xs text-slate-500">
                                @if ($item->course_status !== 'published')
                                    <span class="font-bold text-rose-600">No longer available</span>
                                @elseif (abs((float) $item->current_price - (float) $item->unit_price) > 0.01)
                                    <span class="font-bold text-amber-700">Price changed — remove and add again to refresh.</span>
                                @else
                                    Course price verified
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                            <strong class="text-lg font-black text-slate-900">৳{{ number_format((float) $item->unit_price, 2) }}</strong>
                            <form method="POST" action="{{ route('course-commerce.cart.remove', $item->cart_item_id) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">Remove</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </section>

            <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-extrabold text-slate-900">Order summary</h2>
                <div class="mt-4 flex justify-between text-sm text-slate-600"><span>Courses ({{ $items->count() }})</span><span>৳{{ number_format($subtotal, 2) }}</span></div>
                <div class="mt-3 border-t border-slate-100 pt-3 flex justify-between text-base font-black text-slate-900"><span>Subtotal</span><span>৳{{ number_format($subtotal, 2) }}</span></div>
                @if ($walletBalance > 0)
                    <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                        <div class="flex justify-between gap-3 text-sm"><span class="font-bold text-emerald-900">Gift wallet balance</span><strong class="text-emerald-800">৳{{ number_format($walletBalance, 2) }}</strong></div>
                        <p class="mt-1 text-[11px] text-emerald-800">Your available gift balance is applied automatically at checkout.</p>
                    </div>
                @endif
                <a href="{{ route('course-commerce.vouchers.form') }}" class="mt-3 block text-center text-xs font-bold text-emerald-700 hover:underline">Buy a Gift Voucher</a>

                <form method="POST" action="{{ route('course-commerce.checkout') }}" class="mt-5 space-y-3">
                    @csrf
                    <label class="block text-xs font-bold text-slate-700">Coupon code
                        <input name="coupon_code" value="{{ old('coupon_code') }}" maxlength="40" placeholder="Enter coupon code (optional)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-normal uppercase">
                    </label>
                    <label class="block text-xs font-bold text-slate-700">Payer mobile number
                        <input name="payer_contact" value="{{ old('payer_contact', auth()->user()->phone) }}" required minlength="7" maxlength="40" placeholder="01XXXXXXXXX" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-normal">
                    </label>
                    <div class="rounded-xl bg-blue-50 p-3 text-xs leading-relaxed text-blue-900">
                        Secure hosted checkout via SSLCOMMERZ. Final price and coupon eligibility are recalculated on the server before payment.
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-blue-700">Continue to secure payment</button>
                </form>
                <p class="mt-3 text-center text-[10px] text-slate-400">Your course access is activated only after verified payment.</p>
            </aside>
        </div>
    @endif
</div>
@endsection
