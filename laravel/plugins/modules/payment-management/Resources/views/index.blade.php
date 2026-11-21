@extends('layouts.admin')
@section('title', 'Payments & Ledger — AEC LMS')
@section('content')
<div class="space-y-8 max-w-7xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Payment Ledger & Reconciliation</h1>

    <section class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-lg font-bold text-slate-900 mb-3">Payment transactions</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b">
                    <tr>
                        <th class="py-2">Transaction Ref</th>
                        <th>Invoice</th>
                        <th>Gateway</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Settled At</th>
                        <th>Refund request</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($transactions as $tx)
                        <tr>
                            <td class="py-2 font-mono">{{ $tx->transaction_ref }}</td>
                            <td class="font-mono">{{ $invoiceIntegrationEnabled ? ($tx->invoice?->invoice_number ?? '—') : 'Fees plugin disabled' }}</td>
                            <td>{{ $tx->gateway_slug }}</td>
                            <td>{{ $tx->currency }} {{ $tx->amount }}</td>
                            <td class="uppercase font-semibold">{{ $tx->status }}</td>
                            <td>{{ $tx->settled_at?->toDateTimeString() ?? '—' }}</td>
                            <td>
                                @if ($tx->status === 'settled')
                                    <form method="POST" action="{{ route('plugins.payments.refund', $tx) }}" class="min-w-56 space-y-1">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <input type="number" name="amount" min="0.01" max="{{ $tx->amount }}" step="0.01" required
                                            class="w-full rounded border-slate-300 text-xs" placeholder="Refund amount">
                                        <input type="text" name="reason" maxlength="255" required
                                            class="w-full rounded border-slate-300 text-xs" placeholder="Mandatory refund reason">
                                        <button type="submit" class="rounded bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">
                                            {{ $tx->gateway_slug === 'offline' ? 'Record offline refund' : 'Request online refund' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="text-slate-400">Not refundable</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-slate-400">No payment transactions recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $transactions->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-bold text-slate-900">Refund history and provider reconciliation</h2>
            <a href="{{ route('plugins.payments.refunds.export') }}" class="rounded border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">Download audit CSV</a>
        </div>
        <p class="text-xs text-slate-500 mb-3">
            Online refund requests require a different authorized approver. Provider-pending or ambiguous refunds reserve their amount until provider reconciliation confirms a final state.
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b">
                    <tr>
                        <th class="py-2">Refund</th>
                        <th>Transaction</th>
                        <th>Gateway</th>
                        <th>Amount</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requester / Approver</th>
                        <th>Provider reference</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($refunds as $refund)
                        <tr>
                            <td class="py-2 font-mono">#{{ $refund->id }}</td>
                            <td class="font-mono">{{ $refund->transaction?->transaction_ref ?? '—' }}</td>
                            <td>{{ $refund->gateway_slug ?? 'offline' }}</td>
                            <td>{{ $refund->transaction?->currency ?? '' }} {{ number_format((float) $refund->amount, 2) }}</td>
                            <td>{{ $refund->reason }}</td>
                            <td class="font-semibold uppercase">{{ $refund->status ?? 'succeeded' }}</td>
                            <td>{{ $refund->requester?->name ?? '—' }} / {{ $refund->approver?->name ?? '—' }}</td>
                            <td class="font-mono">{{ $refund->provider_refund_reference ?? '—' }}</td>
                            <td>
                                @if ($refund->status === 'requested')
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('plugins.payments.refunds.approve', $refund) }}">
                                            @csrf
                                            <button type="submit" class="rounded bg-amber-600 px-3 py-1.5 font-semibold text-white">
                                                Approve & submit
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('plugins.payments.refunds.reject', $refund) }}">
                                            @csrf
                                            <button type="submit" class="rounded border border-rose-300 px-3 py-1.5 font-semibold text-rose-700">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                @elseif (in_array($refund->status, ['submitted', 'pending', 'requires_review'], true))
                                    <form method="POST" action="{{ route('plugins.payments.refunds.reconcile', $refund) }}">
                                        @csrf
                                        <button type="submit" class="rounded bg-slate-800 px-3 py-1.5 font-semibold text-white">
                                            Query provider
                                        </button>
                                    </form>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-4 text-slate-400">No refunds recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
