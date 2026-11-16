@extends('layouts.admin')
@section('title', 'Fees & Invoices — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Fees, Invoices & Scholarships</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Invoice #</th><th>Student</th><th>Net</th><th>Paid</th><th>Status</th><th>Due Date</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($invoices as $inv)
                    <tr>
                        <td class="py-2 font-mono">{{ $inv->invoice_number }}</td>
                        <td>{{ $studentIntegration ? ($inv->student?->student_code ?? '—') : 'Student module disabled' }}</td>
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
