@extends('layouts.app')
@section('title', "Shopping Cart — Alom's English Center")
@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 space-y-6">
    <div class="flex items-center justify-between gap-3">
        <div><h1 class="text-2xl font-extrabold text-slate-900">Your Shopping Cart</h1><p class="text-sm text-slate-500">Courses, eBooks, mock tests, gift courses, vouchers and learning services in one place.</p></div>
        <div class="flex items-center gap-2">
            <a href="{{ route('course-commerce.gifts.sent') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700">Gifts You Sent</a>
            <a href="{{ route('plugins.commerce_cart.gifts') }}" class="rounded-lg border border-indigo-200 px-3 py-2 text-sm font-bold text-indigo-700">Send a Gift</a>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold">{{ $items->count() }} items</span>
        </div>
    </div>
    @if (session('status')) <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{{ $errors->first() }}</div> @endif
    @forelse ($items as $item)
        <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
            <div><div class="font-bold text-slate-900">{{ $item->title_snapshot }}</div><div class="mt-1 text-xs uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', $item->item_type) }}</div><div class="mt-1 text-sm font-semibold">৳{{ number_format((float) $item->unit_price, 2) }} each</div></div>
            <div class="flex items-center gap-3">
                <form method="POST" action="{{ route('plugins.commerce_cart.update', $item->id) }}" class="flex items-center gap-2">@csrf @method('PATCH')<input type="number" name="quantity" min="1" max="20" value="{{ $item->quantity }}" class="w-20 rounded-lg border px-2 py-2"><button class="rounded-lg border px-3 py-2 text-sm font-semibold">Update</button></form>
                <form method="POST" action="{{ route('plugins.commerce_cart.remove', $item->id) }}">@csrf @method('DELETE')<button class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-700">Remove</button></form>
            </div>
            <div class="font-extrabold">৳{{ number_format((float) $item->unit_price * (int) $item->quantity, 2) }}</div>
        </div>
    @empty
        <div class="rounded-2xl border border-dashed border-slate-300 p-12 text-center"><h2 class="font-bold text-slate-800">Your cart is empty</h2><p class="mt-2 text-sm text-slate-500">Browse courses, eBooks and other learning services to get started.</p><a href="/courses" class="mt-4 inline-flex rounded-lg bg-slate-900 px-5 py-2 text-sm font-bold text-white">Browse Courses</a></div>
    @endforelse
    @if ($items->isNotEmpty())
        <div class="ml-auto max-w-sm rounded-2xl border border-slate-200 bg-white p-5"><div class="flex justify-between text-sm text-slate-500"><span>Subtotal</span><span>৳{{ number_format($subtotal, 2) }}</span></div><div class="mt-2 flex justify-between text-lg font-extrabold"><span>Total</span><span>৳{{ number_format($subtotal, 2) }}</span></div><p class="mt-3 text-xs text-slate-500">Final price and availability will be revalidated at checkout.</p></div>
        <form method="POST" action="{{ route('plugins.commerce_cart.checkout') }}" enctype="multipart/form-data" class="ml-auto max-w-sm space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
            @csrf
            <h2 class="font-bold text-slate-900">Secure Checkout</h2>
            @if ($couponAvailable ?? false)
                <div><label for="coupon_code" class="mb-1 block text-sm font-semibold text-slate-700">Course coupon code</label><input id="coupon_code" name="coupon_code" maxlength="40" value="{{ old('coupon_code') }}" placeholder="Enter coupon code" class="w-full rounded-lg border px-3 py-2 text-sm"><p class="mt-1 text-xs text-slate-500">Valid coupons apply to eligible course items in this cart.</p></div>
            @endif
            <input name="payer_name" required maxlength="160" placeholder="Full name" value="{{ old('payer_name', auth()->user()->name) }}" class="w-full rounded-lg border px-3 py-2 text-sm">
            <input name="payer_email" required type="email" maxlength="180" placeholder="Email address" value="{{ old('payer_email', auth()->user()->email) }}" class="w-full rounded-lg border px-3 py-2 text-sm">
            <input name="payer_phone" required maxlength="40" placeholder="Mobile number" value="{{ old('payer_phone', auth()->user()->phone) }}" class="w-full rounded-lg border px-3 py-2 text-sm">
            <select name="gateway" required class="w-full rounded-lg border bg-white px-3 py-2 text-sm">
                <option value="gateway-sslcommerz">SSLCOMMERZ (bKash, Nagad, Cards &amp; Internet Banking)</option>
                <option value="gateway-stripe">Stripe (Card)</option>
                <option value="gateway-paypal">PayPal</option>
                @if ($manualPaymentAvailable ?? false)
                    <option value="bank_cash" {{ old('gateway') === 'bank_cash' ? 'selected' : '' }}>Bank Transfer / Cash (Manual Verification)</option>
                @endif
            </select>
            @if ($manualPaymentAvailable ?? false)
                <div class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <p class="text-xs text-amber-900">For manual bank or cash payment only: enter your bank transaction ID / cash receipt reference and optionally attach a receipt image or PDF. Access is granted after staff verification.</p>
                    <input name="external_trx_id" maxlength="120" value="{{ old('external_trx_id') }}" placeholder="Bank transaction ID / receipt reference" class="w-full rounded-lg border px-3 py-2 text-sm">
                    <input name="proof_file" type="file" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-xs">
                </div>
            @endif
            <button type="submit" class="w-full rounded-lg bg-slate-900 px-5 py-3 text-sm font-extrabold text-white">Continue to Secure Payment</button>
            <p class="text-xs text-slate-500">Prices and availability are checked again before payment. Access is granted only after verified settlement.</p>
        </form>
    @endif
</div>
@endsection
