@extends('layouts.admin')
@section('title','Student Attendance Report — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-extrabold">Student Attendance Reports</h1><p class="text-sm text-slate-500">Batch-wise daily attendance, trends and downloadable reports</p></div><a href="{{ route('plugins.attendance.index') }}" class="rounded-lg border px-3 py-2 text-sm">Attendance register</a></div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
        @foreach(['total'=>'Total','present_count'=>'Present','absent_count'=>'Absent','late_count'=>'Late','excused_count'=>'Excused'] as $key=>$label)
        <div class="rounded-xl border bg-white p-4"><div class="text-xs text-slate-500">{{ $label }}</div><div class="mt-1 text-2xl font-bold tabular-nums">{{ (int)($totals->$key ?? 0) }}</div></div>
        @endforeach
    </div>
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
        <label class="text-xs font-semibold">From<input type="date" name="from" value="{{ $filters['from']??'' }}" class="mt-1 block rounded border-slate-300"></label>
        <label class="text-xs font-semibold">To<input type="date" name="to" value="{{ $filters['to']??'' }}" class="mt-1 block rounded border-slate-300"></label>
        <label class="text-xs font-semibold">Batch<select name="batch_id" class="mt-1 block rounded border-slate-300"><option value="">All batches</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" @selected((string)($filters['batch_id']??'')===(string)$batch->id)>{{ $batch->display_name }}</option>@endforeach</select></label>
        <label class="text-xs font-semibold">Status<select name="status" class="mt-1 block rounded border-slate-300"><option value="">All statuses</option>@foreach(['present','absent','late','excused'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <button class="rounded bg-slate-800 px-4 py-2 text-xs font-bold text-white">Apply filters</button>
        <a href="{{ route('plugins.attendance.reports.export') }}?{{ http_build_query($filters) }}" class="rounded bg-emerald-700 px-4 py-2 text-xs font-bold text-white">Export CSV</a>
    </form>
    <div class="overflow-x-auto rounded-2xl border bg-white p-5"><table class="w-full text-left text-xs"><thead class="border-b text-slate-500"><tr><th class="py-2">Date</th><th>Batch</th><th>Total marked</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Attendance %</th></tr></thead><tbody class="divide-y">
    @forelse($rows as $row)<tr><td class="py-3">{{ \Illuminate\Support\Carbon::parse($row->attendance_date)->toDateString() }}</td><td class="font-semibold">{{ $row->batch_name }}</td><td>{{ $row->total }}</td><td class="text-emerald-700">{{ $row->present_count }}</td><td class="text-red-700">{{ $row->absent_count }}</td><td>{{ $row->late_count }}</td><td>{{ $row->excused_count }}</td><td class="font-bold">{{ $row->total ? number_format(($row->present_count+$row->late_count)/$row->total*100,1) : '0.0' }}%</td></tr>@empty<tr><td colspan="8" class="py-6 text-center text-slate-400">No attendance data for selected filters.</td></tr>@endforelse
    </tbody></table>{{ $rows->links() }}</div>
</div>
@endsection
