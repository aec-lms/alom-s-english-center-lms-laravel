@extends('layouts.app')

@section('title', 'Buy a Gift Voucher — AEC LMS')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
        <div class="bg-gradient-to-br from-emerald-950 via-teal-900 to-cyan-900 p-7 text-white">
            <p class="text-xs font-bold uppercase tracking-[.2em] text-emerald-200">Share the gift of learning</p>
            <h1 class="mt-2 text-3xl font-black">Course Gift Voucher</h1>
            <p class="mt-2 text-sm text-emerald-100">Choose an amount. Your recipient can claim the value into their AEC course wallet and use it at checkout.</p>
        </div>
        <form method="POST" action="{{ route('course-commerce.vouchers.purchase') }}" class="space-y-5 p-6 sm:p-8">
            @csrf
            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <label class="block text-sm font-bold text-slate-700">Voucher amount (BDT)
                <input type="number" name="amount" required min="100" max="100000" step="0.01" value="{{ old('amount', 500) }}" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
            </label>
            <label class="block text-sm font-bold text-slate-700">Recipient email
                <input type="email" name="recipient_email" required maxlength="190" value="{{ old('recipient_email') }}" placeholder="friend@example.com" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
            </label>
            <label class="block text-sm font-bold text-slate-700">Recipient name (optional)
                <input name="recipient_name" maxlength="160" value="{{ old('recipient_name') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
            </label>
            <label class="block text-sm font-bold text-slate-700">Personal message (optional)
                <textarea name="message" maxlength="1000" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">{{ old('message') }}</textarea>
            </label>
            <label class="block text-sm font-bold text-slate-700">Payer mobile number
                <input name="payer_contact" required minlength="7" maxlength="40" value="{{ old('payer_contact', auth()->user()->phone) }}" placeholder="01XXXXXXXXX" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal">
            </label>
            <div class="rounded-xl bg-emerald-50 p-4 text-xs leading-relaxed text-emerald-900">Voucher delivery starts only after verified SSLCOMMERZ payment. The recipient must claim it with this email address. Valid for 180 days.</div>
            <button type="submit" class="w-full rounded-xl bg-emerald-600 px-5 py-3 font-extrabold text-white hover:bg-emerald-700">Continue to secure voucher payment</button>
        </form>
    </div>
</div>
@endsection
