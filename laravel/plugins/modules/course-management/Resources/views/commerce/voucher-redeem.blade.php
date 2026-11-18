@extends('layouts.app')

@section('title', 'Claim Gift Voucher — AEC LMS')

@section('content')
<div class="mx-auto max-w-xl px-4 py-12">
    <section class="rounded-3xl border border-slate-200 bg-white p-7 text-center shadow-xl sm:p-9">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50 text-3xl">🎟️</div>
        <p class="mt-5 text-xs font-bold uppercase tracking-[.2em] text-emerald-700">Course wallet gift</p>
        <h1 class="mt-2 text-3xl font-black text-slate-900">{{ $voucher->currency }} {{ number_format((float) $voucher->remaining_amount, 2) }}</h1>
        @if ($voucher->recipient_name)<p class="mt-2 text-sm text-slate-600">For {{ $voucher->recipient_name }}</p>@endif
        @if ($voucher->message)<blockquote class="mt-5 rounded-xl bg-slate-50 p-4 text-sm italic text-slate-600">{{ $voucher->message }}</blockquote>@endif
        <p class="mt-5 text-xs text-slate-500">Signed in as {{ auth()->user()->email }}. Claiming credits the voucher value to your course wallet.</p>
        <form method="POST" action="{{ route('course-commerce.vouchers.redeem', $token) }}" class="mt-6">
            @csrf
            <button type="submit" class="w-full rounded-xl bg-emerald-600 px-5 py-3 font-extrabold text-white hover:bg-emerald-700">Claim voucher to my course wallet</button>
        </form>
    </section>
</div>
@endsection
