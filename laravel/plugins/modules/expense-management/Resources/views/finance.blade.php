@extends('layouts.admin')
@section('title', 'Finance Summary — AEC LMS')
@section('content')
<div class="max-w-5xl space-y-6"><div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-extrabold">Finance Summary</h1><p class="mt-1 text-sm text-slate-500">Collected payments less refunds and approved expenses.</p></div><a href="{{ route('plugins.expenses.index') }}" class="text-sm text-sky-700 underline">← Expenses</a></div>
<form method="GET" class="flex flex-wrap gap-2"><input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="rounded border px-3 py-2"><input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="rounded border px-3 py-2"><button class="rounded bg-slate-900 px-4 py-2 text-white">Apply period</button></form>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach(['Collected Payments'=>$collected,'Refunds'=>$refunds,'Approved Expenses'=>$expenses,'Net Balance'=>$netBalance] as $label=>$amount)<div class="rounded-2xl border bg-white p-5"><p class="text-xs text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-extrabold">৳{{ number_format((float)$amount,2) }}</p></div>@endforeach</div>
<p class="text-xs text-slate-500">Payment and refund totals are included only when the Payment Management plugin is enabled. Pending/rejected expenses are excluded from the net balance.</p></div>
@endsection
