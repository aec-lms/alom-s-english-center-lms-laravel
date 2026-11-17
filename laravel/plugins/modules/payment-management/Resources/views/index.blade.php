@extends('layouts.admin')
@section('title', 'Payments & Ledger — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Payment Ledger & Reconciliation</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Transaction Ref</th><th>Invoice</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Settled At</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($transactions as $tx)
                    <tr>
                        <td class="py-2 font-mono">{{ $tx->transaction_ref }}</td>
                        <td class="font-mono">{{ $invoiceIntegrationEnabled ? ($tx->invoice?->invoice_number ?? '—') : 'Fees plugin disabled' }}</td>
                        <td>{{ $tx->gateway_slug }}</td>
                        <td>{{ $tx->currency }} {{ $tx->amount }}</td>
                        <td class="uppercase font-semibold">{{ $tx->status }}</td>
                        <td>{{ $tx->settled_at?->toDateTimeString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No payment transactions recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
