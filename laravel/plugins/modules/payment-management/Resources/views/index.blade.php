@extends('layouts.admin')
@section('title', 'Payments & Ledger — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Payment Ledger & Reconciliation</h1>
    @if(session('status'))<div role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    @if($invoiceIntegrationEnabled)
    <section class="space-y-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Outstanding balance</div><div class="mt-1 text-xl font-bold">৳{{ number_format($collectionTotals['outstanding'],2) }}</div></div>
            <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Overdue invoices</div><div class="mt-1 text-xl font-bold">{{ $collectionTotals['overdue'] }}</div></div>
            <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Partially paid</div><div class="mt-1 text-xl font-bold">{{ $collectionTotals['partial'] }}</div></div>
            <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Unpaid</div><div class="mt-1 text-xl font-bold">{{ $collectionTotals['unpaid'] }}</div></div>
        </div>
        <div class="overflow-x-auto rounded-2xl border bg-white p-5">
            <h2 class="mb-3 text-lg font-bold">Collect advance / due / installment</h2>
            <p class="mb-3 text-xs text-slate-500">Record cash, bank or other offline collections. Partial amounts are accepted and every receipt is settled against the invoice balance.</p>
            <table class="w-full text-left text-xs"><thead class="border-b text-slate-500"><tr><th class="py-2">Invoice</th><th>Payer</th><th>Net</th><th>Paid</th><th>Due</th><th>Due date</th><th>Collection</th></tr></thead><tbody class="divide-y">
            @forelse($openInvoices as $invoice)
                @php($remaining = max(0, (float)$invoice->net_amount - (float)$invoice->paid_amount))
                <tr><td class="py-3 font-mono">{{ $invoice->invoice_number }}</td><td>{{ $invoice->payer_name ?: 'Student #'.$invoice->student_id }}</td><td>৳{{ number_format((float)$invoice->net_amount,2) }}</td><td>৳{{ number_format((float)$invoice->paid_amount,2) }}</td><td class="font-bold text-amber-700">৳{{ number_format($remaining,2) }}</td><td>{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td><td><form method="POST" action="{{ route('plugins.payments.offline') }}" class="flex min-w-64 gap-2">@csrf<input type="hidden" name="invoice_id" value="{{ $invoice->id }}"><input type="number" name="amount" min="1" max="{{ $remaining }}" step="0.01" required placeholder="Amount" aria-label="Payment amount" class="w-28 rounded border-slate-300 text-xs"><input name="receipt_note" maxlength="160" placeholder="Cash / bank receipt" aria-label="Receipt note" class="w-32 rounded border-slate-300 text-xs"><button class="rounded bg-emerald-700 px-3 py-2 text-xs font-bold text-white">Collect</button></form></td></tr>
            @empty<tr><td colspan="7" class="py-5 text-center text-slate-400">No outstanding invoices.</td></tr>@endforelse
            </tbody></table>
        </div>
    </section>
    @endif
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
