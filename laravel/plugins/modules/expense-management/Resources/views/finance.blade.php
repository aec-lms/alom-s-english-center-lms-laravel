@extends('layouts.admin')
@section('title', 'Finance Summary — AEC LMS')
@section('content')
<div class="max-w-7xl space-y-6">
  <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-extrabold">Finance Reconciliation</h1><p class="mt-1 text-sm text-slate-500">Invoice collections, outstanding balances, refunds and approved expenses from canonical ledgers.</p></div><a href="{{ route('plugins.expenses.index') }}" class="text-sm text-sky-700 underline">← Expenses</a></div>
  <form method="GET" class="flex flex-wrap gap-2"><input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="rounded border px-3 py-2"><input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="rounded border px-3 py-2"><button class="rounded bg-slate-900 px-4 py-2 text-white">Apply period</button></form>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach(['Collected Payments'=>$collected,'Refunds'=>$refunds,'Approved Expenses'=>$expenses,'Net Balance'=>$netBalance,'Issued Invoice Amount'=>$issuedAmount,'Outstanding Invoice Balance'=>$outstanding] as $label=>$amount)
      <div class="rounded-2xl border bg-white p-5"><p class="text-xs text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-extrabold">৳{{ number_format((float)$amount,2) }}</p></div>
    @endforeach
  </div>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Invoices Issued</p><p class="text-xl font-bold">{{ number_format($issuedInvoices) }}</p></div>
    <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Payment Transactions</p><p class="text-xl font-bold">{{ number_format($paymentCount) }}</p></div>
    <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Approved Expense Records</p><p class="text-xl font-bold">{{ number_format($expenseCount) }}</p></div>
    <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Net after refunds & expenses</p><p class="text-xl font-bold">৳{{ number_format((float)$netBalance,2) }}</p></div>
  </div>
  <div class="grid gap-5 lg:grid-cols-2">
    <section class="rounded-2xl border bg-white p-5"><h2 class="mb-3 font-bold">Collections by payment method</h2><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2">Method / Gateway</th><th>Transactions</th><th class="text-right">Amount</th></tr></thead><tbody>@forelse($paymentMethods as $method)<tr class="border-b"><td class="py-2">{{ strtoupper(str_replace(['gateway-','_'],['',' '],$method->method)) }}</td><td>{{ number_format($method->transactions) }}</td><td class="text-right">৳{{ number_format((float)$method->total,2) }}</td></tr>@empty<tr><td colspan="3" class="py-5 text-center text-slate-500">No payment records for this period.</td></tr>@endforelse</tbody></table></div></section>
    <section class="rounded-2xl border bg-white p-5"><h2 class="mb-3 font-bold">Approved expenses by category</h2><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2">Category</th><th>Records</th><th class="text-right">Amount</th></tr></thead><tbody>@forelse($expenseCategoryTotals as $category)<tr class="border-b"><td class="py-2">{{ $category->category }}</td><td>{{ number_format($category->records) }}</td><td class="text-right">৳{{ number_format((float)$category->total,2) }}</td></tr>@empty<tr><td colspan="3" class="py-5 text-center text-slate-500">No approved expenses for this period.</td></tr>@endforelse</tbody></table></div></section>
  </div>
  <p class="text-xs text-slate-500">Outstanding is calculated from fee invoice net amount minus canonical paid amount. Collected totals include settled gateway payments and verified manual bank/cash receipts. Pending and rejected expenses are excluded. All figures respect the current branch access scope.</p>
</div>
@endsection
