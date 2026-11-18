@extends('layouts.admin')
@section('title', 'Expense Management — AEC LMS')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-extrabold text-slate-900">Expense Management</h1><p class="text-sm text-slate-500">Track institution and branch operating expenses.</p></div>
        <div class="rounded-xl border bg-white px-5 py-3"><div class="text-xs text-slate-500">Filtered total</div><div class="text-xl font-bold">{{ number_format($total, 2) }} BDT</div></div>
    </div>
    @if(session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg bg-red-50 p-3 text-red-800">{{ $errors->first() }}</div>@endif
    <section class="rounded-2xl border bg-white p-5">
        <h2 class="mb-4 font-bold">Record new expense</h2>
        <form method="POST" action="{{ route('plugins.expenses.store') }}" class="grid gap-3 md:grid-cols-3">
            @csrf
            @unless($isBranchManager)
                <label class="text-sm">Branch ID (blank = head office)<input name="branch_id" type="number" min="1" class="mt-1 w-full rounded-lg border p-2"></label>
            @endunless
            <label class="text-sm">Category<input name="category" required maxlength="80" class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm">Title<input name="title" required maxlength="160" class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm">Amount (BDT)<input name="amount" type="number" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm">Payment method<select name="payment_method" class="mt-1 w-full rounded-lg border p-2">@foreach(['cash','bkash','nagad','bank','card','other'] as $method)<option value="{{ $method }}">{{ ucfirst($method) }}</option>@endforeach</select></label>
            <label class="text-sm">Expense date<input name="expense_date" type="date" required value="{{ now()->toDateString() }}" class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm">Reference<input name="reference" maxlength="120" class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm md:col-span-2">Description<textarea name="description" maxlength="2000" class="mt-1 w-full rounded-lg border p-2"></textarea></label>
            <div class="md:col-span-3"><button class="rounded-lg bg-sky-700 px-5 py-2 font-semibold text-white">Save expense</button></div>
        </form>
    </section>
    <section class="rounded-2xl border bg-white p-5">
        <form method="GET" class="mb-4 grid gap-2 md:grid-cols-4">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-lg border p-2">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-lg border p-2">
            <select name="category" class="rounded-lg border p-2"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>@endforeach</select>
            <button class="rounded-lg border px-4 py-2">Filter</button>
        </form>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-2">Date</th><th class="p-2">Title</th><th class="p-2">Category</th><th class="p-2">Method</th><th class="p-2 text-right">Amount</th></tr></thead><tbody>
        @forelse($expenses as $expense)<tr class="border-b"><td class="p-2">{{ $expense->expense_date->format('Y-m-d') }}</td><td class="p-2 font-medium">{{ $expense->title }}<div class="text-xs text-slate-500">{{ $expense->description }}</div></td><td class="p-2">{{ $expense->category }}</td><td class="p-2">{{ strtoupper($expense->payment_method) }}</td><td class="p-2 text-right">{{ number_format((float) $expense->amount, 2) }}</td></tr>
        @empty<tr><td colspan="5" class="p-5 text-center text-slate-500">No expenses found.</td></tr>@endforelse
        </tbody></table></div>{{ $expenses->links() }}
    </section>
</div>
@endsection
