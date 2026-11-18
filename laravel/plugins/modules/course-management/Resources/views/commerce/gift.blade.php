@extends('layouts.app')

@section('title', 'Gift ' . $course->title . ' — AEC LMS')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
        <div class="bg-gradient-to-br from-indigo-950 via-blue-950 to-violet-900 p-7 text-white">
            <p class="text-xs font-bold uppercase tracking-[.2em] text-indigo-200">Give the gift of learning</p>
            <h1 class="mt-2 text-3xl font-black">Gift this course</h1>
            <p class="mt-2 text-sm text-indigo-100">{{ $course->title }}</p>
            <p class="mt-4 text-2xl font-black">৳{{ number_format((float) $course->price, 2) }}</p>
        </div>
        <form method="POST" action="{{ route('course-commerce.gifts.create', $course->id) }}" class="space-y-5 p-6 sm:p-8">
            @csrf
            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <label class="block text-sm font-bold text-slate-700">Recipient email
                <input type="email" name="recipient_email" required maxlength="190" value="{{ old('recipient_email') }}" placeholder="friend@example.com" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
                <span class="mt-1 block text-xs font-normal text-slate-500">The recipient must sign in or register with this exact email address to redeem the gift.</span>
            </label>
            <label class="block text-sm font-bold text-slate-700">Recipient name (optional)
                <input name="recipient_name" maxlength="160" value="{{ old('recipient_name') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
            </label>
            <label class="block text-sm font-bold text-slate-700">Personal message (optional)
                <textarea name="message" maxlength="1000" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">{{ old('message') }}</textarea>
            </label>
            <label class="block text-sm font-bold text-slate-700">Gift coupon (optional)
                <input name="coupon_code" maxlength="40" value="{{ old('coupon_code') }}" placeholder="Enter gift coupon code" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal uppercase">
                <span class="mt-1 block text-xs font-normal text-slate-500">Only eligible active coupons can be applied. Discount is confirmed before secure payment.</span>
            </label>
            <label class="block text-sm font-bold text-slate-700">Payer mobile number
                <input name="payer_contact" required minlength="7" maxlength="40" value="{{ old('payer_contact', auth()->user()->phone) }}" placeholder="01XXXXXXXXX" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
            </label>
            <div class="rounded-xl bg-blue-50 p-4 text-xs leading-relaxed text-blue-900">Payment is processed through secure SSLCOMMERZ checkout. The gift becomes redeemable only after the payment provider verifies the transaction.</div>
            <button type="submit" class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-extrabold text-white hover:bg-indigo-700">Continue to secure gift payment</button>
        </form>
    </div>
</div>
@endsection
