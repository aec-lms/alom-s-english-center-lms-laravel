@extends('layouts.admin')
@section('title', 'Fees & Invoices — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Fees, Invoices &amp; Scholarships</h1>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Invoices</div><div class="mt-1 text-xl font-bold">{{ (int)($totals->invoice_count ?? 0) }}</div></div>
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Total billed</div><div class="mt-1 text-xl font-bold">৳{{ number_format((float)($totals->billed ?? 0),2) }}</div></div>
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Collected</div><div class="mt-1 text-xl font-bold text-emerald-700">৳{{ number_format((float)($totals->collected ?? 0),2) }}</div></div>
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">Outstanding / overdue</div><div class="mt-1 text-xl font-bold text-amber-700">৳{{ number_format((float)($totals->outstanding ?? 0),2) }}</div><div class="text-xs text-slate-500">{{ $overdueCount }} overdue invoices</div></div>
    </div>
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
        <label class="text-xs font-semibold">Search<input name="q" value="{{ $filters['q']??'' }}" placeholder="Invoice / payer" class="mt-1 block rounded border-slate-300"></label>
        <label class="text-xs font-semibold">Status<select name="status" class="mt-1 block rounded border-slate-300"><option value="">All</option>@foreach(['unpaid','partial','paid','overdue'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label class="text-xs font-semibold">From<input type="date" name="from" value="{{ $filters['from']??'' }}" class="mt-1 block rounded border-slate-300"></label>
        <label class="text-xs font-semibold">To<input type="date" name="to" value="{{ $filters['to']??'' }}" class="mt-1 block rounded border-slate-300"></label>
        <button class="rounded bg-slate-800 px-4 py-2 text-xs font-bold text-white">Filter report</button>
        <a href="{{ route('plugins.fees.index') }}" class="text-xs underline">Clear</a>
    </form>
    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="font-bold text-lg">Create Fee Invoice</h2>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 p-3 text-sm">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="rounded bg-emerald-50 text-emerald-700 p-3 text-sm">{{ session('status') }}</div>@endif
        <form method="POST" action="{{ route('plugins.fees.invoices.store') }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            @if($studentIntegrationEnabled)
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold mb-1">Student (optional)</label>
                    <select name="student_id" class="w-full rounded border border-slate-300 p-2">
                        <option value="">Standalone payer / customer</option>
                        @foreach(\Plugins\Modules\StudentManagement\Models\Student::with('user')->orderBy('id')->get() as $student)
                            <option value="{{ $student->id }}">{{ $student->student_code }} — {{ $student->user?->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <label class="text-sm">Payer Name<input name="payer_name" class="mt-1 w-full rounded border border-slate-300 p-2" maxlength="160"></label>
            <label class="text-sm">Payer Email<input name="payer_email" type="email" class="mt-1 w-full rounded border border-slate-300 p-2" maxlength="180"></label>
            <label class="text-sm">Payer Phone<input name="payer_phone" class="mt-1 w-full rounded border border-slate-300 p-2" maxlength="40"></label>
            <label class="text-sm">Fee Category
                <select name="fee_category_id" class="mt-1 w-full rounded border border-slate-300 p-2"><option value="">General</option>
                    @foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">Subtotal<input name="subtotal_amount" type="number" min="0.01" step="0.01" required class="mt-1 w-full rounded border border-slate-300 p-2"></label>
            <label class="text-sm">Discount<input name="discount_amount" type="number" min="0" step="0.01" value="0" class="mt-1 w-full rounded border border-slate-300 p-2"></label>
            <label class="text-sm">Due Date<input name="due_date" type="date" class="mt-1 w-full rounded border border-slate-300 p-2"></label>
            <div class="md:col-span-2"><button class="rounded bg-sky-600 text-white px-4 py-2 font-bold">Create Invoice</button></div>
        </form>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 overflow-x-auto">
        <h2 class="font-bold text-lg mb-3">Invoices & Outstanding Balances</h2>
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Invoice #</th><th>Payer / Student</th><th>Net</th><th>Paid</th><th>Status</th><th>Due Date</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($invoices as $inv)
                    <tr>
                        <td class="py-2 font-mono">{{ $inv->invoice_number }}</td>
                        <td><div class="font-semibold">{{ $inv->payer_name ?? ($inv->student?->user?->name ?? '—') }}</div><div class="text-slate-500">{{ $inv->payer_email ?? $inv->student?->user?->email }}</div><div class="text-slate-500">{{ $inv->payer_phone ?? $inv->student?->user?->phone }}</div></td>
                        <td>{{ $inv->currency }} {{ number_format((float) $inv->net_amount, 2) }}</td>
                        <td>{{ $inv->currency }} {{ number_format((float) $inv->paid_amount, 2) }}</td>
                        <td class="uppercase font-semibold">{{ $inv->status }}</td>
                        <td>{{ $inv->due_date?->toDateString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No invoices issued yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $invoices->links() }}
    </section>
</div>
@endsection
