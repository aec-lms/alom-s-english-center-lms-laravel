@extends('layouts.admin')
@section('title', 'Finance Summary — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold">Finance Summary</h1>
            <p class="mt-1 text-sm text-slate-500">Collected receipts less refunds and approved expenses, with invoice balance visibility.</p>
        </div>
        <a href="{{ route('plugins.expenses.index') }}" class="text-sm text-sky-700 underline">← Expenses</a>
    </div>

    <form method="GET" class="flex flex-wrap gap-2">
        <input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="rounded border px-3 py-2">
        <input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="rounded border px-3 py-2">
        <button class="rounded bg-slate-900 px-4 py-2 text-white">Apply period</button>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach(['Collected Payments' => $collected, 'Refunds' => $refunds, 'Approved Expenses' => $expenses, 'Net Balance' => $netBalance] as $label => $amount)
            <div class="rounded-2xl border bg-white p-5">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold">৳{{ number_format((float) $amount, 2) }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            'Invoices Issued' => $issuedInvoices,
            'Invoice Value' => '৳' . number_format((float) $issuedAmount, 2),
            'Outstanding Balance' => '৳' . number_format((float) $outstanding, 2),
            'Payment Transactions' => $paymentCount,
            'Refund Records' => $refundCount,
            'Approved Expense Records' => $expenseCount,
        ] as $label => $value)
            <div class="rounded-xl border bg-white p-4">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if($methodTotals->isNotEmpty())
        <section class="rounded-2xl border bg-white p-5 space-y-3">
            <h2 class="font-bold text-slate-900">Collected by Payment Method / Gateway</h2>
            <div class="divide-y">
                @foreach($methodTotals as $method)
                    <div class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="text-slate-600">{{ ucwords(str_replace(['gateway-', '_', '-'], ['', ' ', ' '], (string) $method->method)) }}</span>
                        <span class="text-right font-semibold">৳{{ number_format((float) $method->total, 2) }} <span class="text-xs font-normal text-slate-500">({{ $method->transactions }} tx)</span></span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($methodTotals->isNotEmpty())
        <section class="rounded-2xl border bg-white p-5 space-y-3">
            <h2 class="font-bold text-slate-900">Collections by payment method</h2>
            <div class="divide-y">
                @foreach($methodTotals as $method)
                    <div class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="text-slate-600">{{ strtoupper(str_replace(['gateway-', '_', '-'], ['', ' ', ' '], (string) $method->method)) }}</span>
                        <span class="text-right font-semibold">৳{{ number_format((float) $method->total, 2) }} <span class="text-xs font-normal text-slate-500">({{ number_format((int) $method->transactions) }} tx)</span></span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="rounded-2xl border bg-white p-5 space-y-3">
        <h2 class="font-bold text-slate-900">Approved expenses by category</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left"><th class="py-2">Category</th><th>Records</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                @forelse($expenseCategoryTotals as $category)
                    <tr class="border-b"><td class="py-2">{{ $category->category }}</td><td>{{ number_format((int) $category->records) }}</td><td class="text-right">৳{{ number_format((float) $category->total, 2) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="py-5 text-center text-slate-500">No approved expenses for this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <p class="text-xs text-slate-500">The selected period filters payment settlement dates, refund record dates, expense dates and invoice issue dates. Outstanding balance is the remaining balance of invoices issued in the selected period. Pending/rejected expenses are excluded. Refund totals include recorded refunds only.</p>
</div>
@endsection
