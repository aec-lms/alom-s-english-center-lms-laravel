@extends('layouts.admin')
@section('title', 'Course Enrollments — AEC LMS')
@section('content')
<div class="max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Course Enrollments</h1>
        <p class="text-sm text-slate-500 mt-1">Paid courses activate automatically after verified invoice settlement. Manual activation cannot bypass payment.</p>
    </div>
    @if (session('status'))
        <div class="rounded-lg bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif
    <div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">
        <table class="w-full text-xs text-left">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="p-3">Student</th><th>Course</th><th>Branch</th><th>Invoice</th><th>Status</th><th>Action</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($enrollments as $enrollment)
                    <tr>
                        <td class="p-3"><div class="font-bold text-slate-800">{{ $enrollment->user?->name }}</div><div class="text-slate-500">{{ $enrollment->user?->email }}</div></td>
                        <td>{{ $enrollment->course?->title }}</td>
                        <td>{{ $enrollment->branch_id ?: 'Global' }}</td>
                        <td>
                            @if ($enrollment->invoice)
                                <div>{{ $enrollment->invoice->invoice_number }}</div>
                                <div class="text-slate-500">{{ $enrollment->invoice->currency }} {{ number_format((float) $enrollment->invoice->net_amount, 2) }} · {{ $enrollment->invoice->status }}</div>
                            @else
                                <span class="text-emerald-700">Free course</span>
                            @endif
                        </td>
                        <td class="uppercase font-bold">{{ $enrollment->status }}</td>
                        <td>
                            <form method="POST" action="{{ route('plugins.enrollments.status', $enrollment) }}" class="flex items-center gap-2">
                                @csrf @method('PATCH')
                                <select name="status" class="rounded border border-slate-300 px-2 py-1">
                                    @foreach (['pending', 'active', 'cancelled', 'completed'] as $status)
                                        <option value="{{ $status }}" @selected($enrollment->status === $status)>{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                                <button class="rounded bg-sky-600 text-white px-3 py-1.5 font-bold">Save</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-slate-400">No enrollment requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $enrollments->links() }}
</div>
@endsection
