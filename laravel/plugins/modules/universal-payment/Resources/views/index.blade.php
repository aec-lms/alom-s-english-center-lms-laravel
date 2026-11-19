@extends('layouts.admin')
@section('title', 'Universal Payment Plugin — AEC LMS')
@section('content')
@php
    $allGateways = [
        'sslcommerz' => 'SSLCommerz',
        'bkash' => 'bKash Merchant',
        'nagad' => 'Nagad Merchant',
        'rocket' => 'Rocket DBBL',
        'card' => 'Visa / Mastercard (Stripe)',
        'paypal' => 'PayPal International',
        'bank_cash' => 'Bank Transfer & Cash Desk',
    ];
@endphp
<div class="space-y-6 max-w-6xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold">Independent Universal Payment Plugin · Zero Dependencies</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Universal Payment Gateway & Ledger</h1>
        <p class="text-xs text-slate-500">Manage enabled payment methods and transaction reconciliation. Each online gateway owns its credentials and hosted-checkout integration.</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <form method="POST" action="{{ route('plugins.universal_payment.gateways') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">1. Multi-Gateway Providers & Credentials</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-2">Active Checkout Providers</label>
                <div class="grid sm:grid-cols-2 gap-2">
                    @foreach ($allGateways as $gKey => $gLabel)
                        <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-50 border border-slate-200 cursor-pointer">
                            <input type="checkbox" name="gateways[]" value="{{ $gKey }}" {{ in_array($gKey, $config['enabled_gateways'], true) ? 'checked' : '' }} class="rounded text-blue-600">
                            <span class="font-semibold">{{ $gLabel }}</span>
                        </label>
                    @endforeach
                </div>
                <label class="flex items-start gap-2 p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
                    <input type="checkbox" name="allow_offline_payments" value="1" {{ $config['allow_offline_payments'] ? 'checked' : '' }} class="mt-0.5 rounded text-blue-600">
                    <span><strong>Allow cash / bank transfer</strong><span class="block mt-0.5 text-[11px]">When disabled, new manual cash and bank-transfer submissions are rejected.</span></span>
                </label>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Default Currency</label>
                    <select name="default_currency" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                        @foreach (['BDT', 'USD', 'EUR', 'GBP'] as $cur)
                            <option value="{{ $cur }}" {{ $config['default_currency'] === $cur ? 'selected' : '' }}>{{ $cur }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-blue-900">
                    <strong>Gateway credentials are managed by their own plugins.</strong>
                    <p class="mt-1">Install and configure the dedicated SSLCOMMERZ Gateway plugin under Plugin Manager. Secrets are never duplicated in this universal ledger.</p>
                </div>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold">Save Gateway Settings</button>
        </form>

        <form method="POST" action="{{ route('plugins.universal_payment.store') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">2. Record / Collect Payment</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Student / Payer Name *</label>
                    <input name="payer_name" required class="w-full rounded-xl border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Phone / Email</label>
                    <input name="payer_contact" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                </div>
                <div class="col-span-2">
                    <label class="font-semibold text-slate-700 block mb-1">Course / E-Book / Fee Item *</label>
                    <input name="item_title" required placeholder="IELTS Masterclass Batch Admission" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Gateway *</label>
                    <select name="gateway" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                        @foreach ($allGateways as $gKey => $gLabel)
                            <option value="{{ $gKey }}">{{ $gLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Amount ({{ $config['default_currency'] }}) *</label>
                    <input type="number" step="0.01" min="1" name="amount" required class="w-full rounded-xl border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">External TrxID / Receipt Reference *</label>
                    <input name="external_trx_id" required class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
                </div>
                <div class="col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-900">
                    Manual payment records always start as pending. An authorized reviewer must verify the receipt before the payment is treated as settled.
                </div>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Record Payment</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Unified Payment Transactions ({{ $transactions->count() }})</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b"><tr><th class="py-2">Ref</th><th>Payer</th><th>Item</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($transactions as $tx)
                        <tr>
                            <td class="py-2.5 font-mono font-semibold">{{ $tx->reference }}</td>
                            <td>{{ $tx->payer_name }}</td>
                            <td>{{ $tx->item_title }}</td>
                            <td class="uppercase">{{ $tx->gateway }}</td>
                            <td class="font-bold">{{ $tx->currency }} {{ number_format((float) $tx->amount, 2) }}</td>
                            <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $tx->status === 'verified' ? 'bg-emerald-100 text-emerald-800' : ($tx->status === 'refunded' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">{{ $tx->status }}</span></td>
                            <td>
                                @if ($tx->proof_path)
                                    <a href="{{ route('plugins.universal_payment.proof', $tx) }}" class="mr-2 text-blue-700 underline">View receipt</a>
                                @endif
                                @if (in_array($tx->gateway, ['bank_cash', 'offline', 'bkash', 'nagad', 'rocket'], true) && $tx->status === 'pending')
                                    <form method="POST" action="{{ route('plugins.universal_payment.status', $tx) }}" class="inline-flex items-center gap-1.5">
                                        @csrf
                                        <input name="payment_reference" required maxlength="120" value="{{ $tx->external_trx_id }}" aria-label="Verified bank receipt reference" class="w-36 rounded border border-slate-300 px-1.5 py-1 text-[11px] font-mono">
                                        <select name="status" class="rounded border border-slate-300 px-1.5 py-1 text-[11px]">
                                            <option value="verified">Verify</option>
                                            <option value="rejected">Reject</option>
                                        </select>
                                        <button class="px-2 py-1 rounded bg-slate-900 text-white text-[11px] font-semibold">Apply</button>
                                    </form>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-slate-400">No transactions recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
