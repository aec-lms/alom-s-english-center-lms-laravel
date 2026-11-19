@extends('layouts.app')

@section('title', 'Gifts You Sent — Alom\'s English Center')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.2em] text-indigo-600">Your generosity</p>
            <h1 class="mt-2 text-3xl font-black text-slate-900">Gifts you sent</h1>
            <p class="mt-2 text-sm text-slate-600">Track delivery, share a secure redemption link, or retry an email that could not be delivered.</p>
        </div>
        <a href="{{ route('course-commerce.cart') }}" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">Open my cart</a>
    </div>

    @if (session('status'))
        <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
    @endif

    @if ($gifts->isEmpty())
        <section class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <div class="text-4xl">🎁</div>
            <h2 class="mt-3 text-xl font-extrabold text-slate-900">No gifts sent yet</h2>
            <p class="mt-2 text-sm text-slate-500">Course gifts and vouchers you purchase will appear here.</p>
        </section>
    @else
        <div class="space-y-4">
            @foreach ($gifts as $gift)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">{{ $gift['type'] }}</span>
                                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $gift['status'] === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ str_replace('_', ' ', ucfirst($gift['status'])) }}</span>
                            </div>
                            <h2 class="mt-3 text-lg font-extrabold text-slate-900">{{ $gift['title'] }}</h2>
                            <p class="mt-1 text-sm text-slate-600">To {{ $gift['recipient_name'] ?: $gift['recipient_email'] }} · {{ $gift['recipient_email'] }}</p>
                            <p class="mt-2 text-sm font-bold text-slate-800">{{ $gift['currency'] }} {{ number_format($gift['amount'], 2) }}</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email delivery</p>
                            <p class="mt-1 text-sm font-bold {{ $gift['delivery_status'] === 'sent' ? 'text-emerald-700' : ($gift['delivery_status'] === 'failed' ? 'text-rose-700' : 'text-amber-700') }}">{{ ucfirst($gift['delivery_status']) }}</p>
                            @if ($gift['sent_at'])
                                <p class="mt-1 text-xs text-slate-500">Accepted {{ \Illuminate\Support\Carbon::parse($gift['sent_at'])->format('M j, Y g:i A') }}</p>
                            @endif
                        </div>
                    </div>

                    @if ($gift['delivery_error'])
                        <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $gift['delivery_error'] }}</p>
                    @endif

                    <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                        @if ($gift['redeem_url'])
                            <a href="{{ $gift['redeem_url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-bold text-indigo-700 hover:bg-indigo-100">Open secure gift link</a>
                            <span class="break-all text-xs text-slate-500">{{ $gift['redeem_url'] }}</span>
                        @elseif ($gift['status'] !== 'paid')
                            <span class="text-xs text-slate-500">The redemption link becomes available after verified payment.</span>
                        @else
                            <span class="text-xs text-slate-500">The secure link is unavailable. Contact support before sharing anything.</span>
                        @endif

                        @if ($emailDeliveryEnabled && $gift['status'] === 'paid' && $gift['delivery_status'] === 'failed' && !$gift['sent_at'])
                            <form method="POST" action="{{ $gift['resend_url'] }}" class="ml-auto">
                                @csrf
                                <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">Retry email delivery</button>
                            </form>
                        @endif
                    </div>
                    @if ($gift['expires_at'])
                        <p class="mt-3 text-xs text-slate-500">Expires: {{ \Illuminate\Support\Carbon::parse($gift['expires_at'])->format('M j, Y') }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
@endsection
