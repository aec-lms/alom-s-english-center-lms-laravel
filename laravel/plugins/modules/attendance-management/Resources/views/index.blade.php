@extends('layouts.admin')
@section('title', 'Attendance Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Attendance & Progress Tracking</h1>
        <p class="text-sm text-slate-500 mt-1">Select a batch and mark the whole class. Changes to saved attendance require a reason.</p>
    </div>
    <div id="attendance-sync-status" role="status" aria-live="polite" class="rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-700">Online sync ready. Pending offline entries are saved on this device.</div>
    <form id="attendance-register-form" method="POST" action="{{ route('plugins.attendance.store') }}" data-sync-url="{{ route('plugins.attendance.sync') }}" data-actor-id="{{ auth()->id() }}" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        @csrf
        <div class="grid gap-4 md:grid-cols-3">
            <label class="block text-sm font-semibold">Batch
                <select id="attendance-batch" name="batch_id" required class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Select batch</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" data-branch="{{ $batch->branch_id }}" data-mapping="{{ $batch->attendance_batch_mapping_id }}">{{ $batch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold">Attendance date
                <input type="date" name="attendance_date" value="{{ now()->toDateString() }}" required class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label class="block text-sm font-semibold">Entry source
                <select name="source" class="mt-1 w-full rounded-lg border-slate-300"><option value="online">Online</option><option value="offline">Offline sync</option></select>
            </label>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-slate-500 border-b"><tr><th class="py-2">Student ID</th><th>Student</th><th>Status</th><th>Remarks</th><th>Edit reason</th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($students as $student)
                        <tr data-student-branch="{{ $student->branch_id }}">
                            <td class="py-2">{{ $student->id }}</td>
                            <td>{{ $student->user?->name ?? $student->student_code }}</td>
                            <td>
                                <select name="records[{{ $loop->index }}][status]" class="rounded-lg border-slate-300"><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select>
                                <input type="hidden" name="records[{{ $loop->index }}][student_id]" value="{{ $student->id }}">
                            </td>
                            <td><input name="records[{{ $loop->index }}][remarks]" maxlength="255" class="rounded-lg border-slate-300 w-40" placeholder="Optional"></td>
                            <td><input name="records[{{ $loop->index }}][edit_reason]" maxlength="255" class="rounded-lg border-slate-300 w-44" placeholder="Required for changes"></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-400">No students available for this branch.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex justify-end"><button type="submit" class="rounded-lg bg-indigo-600 text-white px-5 py-2 font-semibold">Save attendance</button></div>
    </form>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="font-bold text-lg mb-3">Recent attendance</h2>
        <div class="overflow-x-auto"><table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Date</th><th>Batch ID</th><th>Student ID</th><th>Branch</th><th>Status</th><th>Remarks</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($records as $rec)
                    <tr><td class="py-2 font-mono">{{ $rec->attendance_date?->toDateString() }}</td><td>{{ $rec->batch_id }}</td><td>{{ $rec->student_id }}</td><td>{{ $rec->branch_id }}</td><td class="uppercase font-semibold">{{ $rec->status }}</td><td>{{ $rec->remarks ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No attendance marked yet.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </div>
</div>
<link rel="manifest" href="{{ asset('attendance.webmanifest') }}">
<link rel="stylesheet" href="{{ asset('css/attendance-offline.css') }}">
<script src="{{ asset('js/attendance-offline.js') }}" defer></script>
@endsection
