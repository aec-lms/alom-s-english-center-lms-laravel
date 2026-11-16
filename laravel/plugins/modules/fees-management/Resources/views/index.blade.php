@extends('layouts.admin')
@section('title', 'Fees & Invoices — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Fees, Invoices & Scholarships</h1>
    <form method="POST" action="{{ route('plugins.fees.invoices.store') }}" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        @csrf
        <h2 class="text-sm font-bold text-slate-900">Create Fee Invoice</h2>
        <div class="grid md:grid-cols-2 gap-3 text-xs">
            @if ($studentIntegration)
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Student (optional)</label>
                    <select name="student_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                        <option value="">Standalone payer / no student</option>
                        @foreach ($students as $student)
                            <option value="{{ $student->id }}">{{ $student->student_code }} — {{ $student->user?->name ?? 'Student' }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Payer Name</label>
                <input name="payer_name" maxlength="160" class="w-full rounded-xl border border-slate-300 px-3 py-2" placeholder="Name for standalone invoice">
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Payer Email</label>
                <input type="email" name="payer_email" maxlength="180" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Payer Phone</label>
                <input name="payer_phone" maxlength="40" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Fee Category</label>
                <select name="fee_category_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="">General fee</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Subtotal (BDT)</label>
                <input type="number" name="subtotal_amount" min="1" step="0.01" required class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Discount (BDT)</label>
                <input type="number" name="discount_amount" min="0" step="0.01" value="0" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Due Date</label>
                <input type="date" name="due_date" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
        </div>
        @if ($errors->any())
            <p class="text-xs text-rose-600">{{ $errors->first() }}</p>
        @endif
        <button class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-bold">Create Invoice</button>
    </form>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Invoice #</th><th>Student</th><th>Net</th><th>Paid</th><th>Status</th><th>Due Date</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($invoices as $inv)
                    <tr>
                        <td class="py-2 font-mono">{{ $inv->invoice_number }}</td>
                        <td>{{ $studentIntegration ? ($inv->student?->student_code ?? $inv->payer_name ?? '—') : ($inv->payer_name ?: 'General payer') }}</td>
                        <td>{{ $inv->currency }} {{ $inv->net_amount }}</td>
                        <td>{{ $inv->currency }} {{ $inv->paid_amount }}</td>
                        <td class="uppercase font-semibold">{{ $inv->status }}</td>
                        <td>{{ $inv->due_date?->toDateString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No invoices issued yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
