@extends('layouts.admin')
@section('title', 'Attendance Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Attendance & Progress Tracking</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Date</th><th>Batch ID</th><th>Student ID</th><th>Branch</th><th>Status</th><th>Remarks</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($records as $rec)
                    <tr>
                        <td class="py-2 font-mono">{{ $rec->attendance_date?->toDateString() }}</td>
                        <td>{{ $rec->batch_id }}</td>
                        <td>{{ $rec->student_id }}</td>
                        <td>{{ $rec->branch_id }}</td>
                        <td class="uppercase font-semibold">{{ $rec->status }}</td>
                        <td>{{ $rec->remarks ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No attendance marked yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
