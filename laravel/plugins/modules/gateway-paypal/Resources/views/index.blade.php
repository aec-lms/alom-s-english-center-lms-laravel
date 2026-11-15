@extends('layouts.admin')
@section('title', 'PayPal Gateway — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">PayPal Orders v2 Payment Gateway</h1>
            <p class="text-xs text-slate-500 mt-0.5">Hosted PayPal Checkout with encrypted Client Secret, idempotent order creation, and signed webhooks.</p>
        </div>
        <a href="{{ route('admin.plugins.configure', 'gateway-paypal') }}" class="px-4 py-2 rounded-lg bg-[#0284C7] text-white text-xs font-bold">Configure PayPal Keys</a>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-5 grid sm:grid-cols-3 gap-4 text-xs">
        <div><span class="text-slate-500">Credentials:</span> <strong class="{{ $configured ? 'text-emerald-700' : 'text-amber-700' }}">{{ $configured ? 'Encrypted & Configured' : 'Not Configured' }}</strong></div>
        <div><span class="text-slate-500">Environment:</span> <strong>{{ $sandbox ? 'Sandbox Mode' : 'Live Production' }}</strong></div>
        <div><span class="text-slate-500">Webhook Endpoint:</span> <code class="font-mono">/payments/paypal/webhook</code></div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-5 text-xs">
        <h2 class="text-sm font-bold text-slate-900 mb-3">Recent PayPal Transactions</h2>
        <div class="space-y-2">
            @forelse ($transactions as $tx)
                <div class="flex items-center justify-between border border-slate-100 rounded-lg px-3 py-2">
                    <span class="font-mono font-bold">{{ $tx->transaction_ref }}</span>
                    <span>{{ $tx->currency }} {{ number_format((float) $tx->amount, 2) }}</span>
                    <span class="uppercase font-bold {{ $tx->status === 'settled' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $tx->status }}</span>
                </div>
            @empty
                <p class="text-slate-400">No PayPal transactions recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
