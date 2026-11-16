@extends('layouts.admin')
@section('title', 'Attendance Management — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-extrabold text-slate-900">Attendance Management</h1><a href="{{ route('plugins.attendance.offline') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Open Offline Attendance</a></div><p class="text-sm text-slate-500">Online attendance, batch registers and audit history</p></div>
    </div>
    @if (session('status'))<div class="rounded-xl bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
    <form method="GET" class="flex flex-wrap gap-3 rounded-2xl border bg-white p-4">
        <select name="batch_id" class="rounded-lg border-slate-300"><option value="">All batches</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" @selected(request('batch_id') == $batch->id)>{{ $batch->name }} ({{ $batch->batch_code }})</option>@endforeach</select>
        <input type="date" name="attendance_date" value="{{ request('attendance_date') }}" class="rounded-lg border-slate-300">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filter</button>
    </form>
    <div class="overflow-x-auto rounded-2xl border bg-white">
        <table class="w-full text-left text-sm"><thead class="border-b bg-slate-50"><tr><th class="p-3">Date</th><th class="p-3">Batch</th><th class="p-3">Student</th><th class="p-3">Branch</th><th class="p-3">Status</th><th class="p-3">Sync</th><th class="p-3">Remarks</th></tr></thead>
        <tbody>@forelse($records as $rec)<tr class="border-b"><td class="p-3">{{ $rec->attendance_date?->toDateString() }}</td><td class="p-3">{{ $rec->batch_id }}</td><td class="p-3">{{ $rec->student_id }}</td><td class="p-3">{{ $rec->branch_id }}</td><td class="p-3 font-semibold uppercase">{{ $rec->status }}</td><td class="p-3">{{ $rec->sync_status }}</td><td class="p-3">{{ $rec->remarks ?? '—' }}</td></tr>@empty<tr><td colspan="7" class="p-6 text-center text-slate-400">No attendance records yet.</td></tr>@endforelse</tbody></table>
    </div>
    {{ $records->links() }}
    <p class="text-xs text-slate-500">Mobile sync endpoint: <code>/api/mobile/attendance/sync</code>. GPS permission is requested only at check-in; no background location collection is performed.</p>
</div>
@endsection
