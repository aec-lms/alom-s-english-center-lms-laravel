@extends('layouts.admin')
@section('title','Teacher Attendance — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-extrabold">Teacher Attendance</h1><p class="text-sm text-slate-500">Daily staff register, punctuality and attendance reporting</p></div><a href="{{ route('plugins.attendance.index') }}" class="rounded-lg border px-3 py-2 text-sm">Student attendance</a></div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['present'=>'Present','late'=>'Late','absent'=>'Absent','leave'=>'On leave'] as $key=>$label)
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">{{ $label }} today</div><div class="mt-1 text-2xl font-bold tabular-nums">{{ (int)($summary[$key] ?? 0) }}</div></div>
        @endforeach
    </div>
    @if(session('status'))<div role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('plugins.attendance.teachers.store') }}" class="grid gap-3 rounded-2xl border bg-white p-5 md:grid-cols-3">@csrf
        <label class="text-sm font-semibold">Teacher<select name="teacher_id" required class="mt-1 w-full rounded-lg border-slate-300">@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->employee_code }} — {{ $teacher->user?->name }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Date<input type="date" name="attendance_date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
        <label class="text-sm font-semibold">Status<select name="status" class="mt-1 w-full rounded-lg border-slate-300"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="leave">Leave</option></select></label>
        <label class="text-sm">Check in<input type="datetime-local" name="check_in_at" class="mt-1 w-full rounded-lg border-slate-300"></label>
        <label class="text-sm">Check out<input type="datetime-local" name="check_out_at" class="mt-1 w-full rounded-lg border-slate-300"></label>
        <label class="text-sm">Remarks<input name="remarks" maxlength="255" class="mt-1 w-full rounded-lg border-slate-300"></label>
        <div class="md:col-span-3"><button class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-bold text-white">Save teacher attendance</button></div>
    </form>
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
        <label class="text-xs font-semibold">From<input type="date" name="from" value="{{ $from['from'] ?? '' }}" class="mt-1 block rounded border-slate-300"></label>
        <label class="text-xs font-semibold">To<input type="date" name="to" value="{{ $from['to'] ?? '' }}" class="mt-1 block rounded border-slate-300"></label>
        <label class="text-xs font-semibold">Status<select name="status" class="mt-1 block rounded border-slate-300"><option value="">All statuses</option>@foreach(['present','late','absent','leave'] as $status)<option value="{{ $status }}" @selected(($from['status'] ?? '')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <button class="rounded bg-slate-800 px-4 py-2 text-xs font-bold text-white">Filter report</button><a href="{{ route('plugins.attendance.teachers.index') }}" class="text-xs underline">Clear</a>
    </form>
    <div class="overflow-x-auto rounded-2xl border bg-white p-5"><h2 class="mb-3 font-bold">Teacher attendance report</h2><table class="w-full text-left text-xs"><thead class="border-b text-slate-500"><tr><th class="py-2">Date</th><th>Teacher</th><th>Employee ID</th><th>Status</th><th>Check in</th><th>Check out</th><th>Source</th><th>Remarks</th></tr></thead><tbody class="divide-y">
    @forelse($records as $record)<tr><td class="py-2">{{ $record->attendance_date?->toDateString() }}</td><td class="font-semibold">{{ $record->teacher?->user?->name ?? 'Deleted teacher' }}</td><td>{{ $record->teacher?->employee_code }}</td><td class="uppercase">{{ $record->status }}</td><td>{{ $record->check_in_at?->format('h:i A') ?? '—' }}</td><td>{{ $record->check_out_at?->format('h:i A') ?? '—' }}</td><td>{{ $record->source }}</td><td>{{ $record->remarks }}</td></tr>@empty<tr><td colspan="8" class="py-6 text-center text-slate-400">No teacher attendance records found.</td></tr>@endforelse
    </tbody></table>{{ $records->links() }}</div>
</div>
@endsection
