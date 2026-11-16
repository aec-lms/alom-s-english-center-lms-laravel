@extends('layouts.app')
@section('title', 'My Attendance — AEC LMS')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="mx-auto max-w-5xl space-y-5 px-4 py-10">
        <div><p class="text-xs font-bold uppercase tracking-wider text-sky-700">{{ $isGuardian ? 'Guardian Portal' : 'Student Portal' }}</p><h1 class="mt-1 text-3xl font-extrabold text-slate-900">{{ $isGuardian ? 'Linked Students Attendance' : 'My Attendance' }}</h1></div>
        <div class="overflow-x-auto rounded-2xl border bg-white">
            <table class="w-full text-left text-sm"><thead class="border-b bg-slate-50"><tr><th class="p-3">Date</th>@if($isGuardian)<th class="p-3">Student</th>@endif<th class="p-3">Batch</th><th class="p-3">Status</th><th class="p-3">Remarks</th></tr></thead>
                <tbody>@forelse($records as $record)<tr class="border-b"><td class="p-3">{{ $record->attendance_date?->toDateString() }}</td>@if($isGuardian)<td class="p-3">{{ $record->student_name }}</td>@endif<td class="p-3">{{ $record->batch_name ?? '—' }}</td><td class="p-3 font-semibold uppercase">{{ $record->status }}</td><td class="p-3">{{ $record->remarks ?? '—' }}</td></tr>@empty<tr><td colspan="{{ $isGuardian ? 5 : 4 }}" class="p-6 text-center text-slate-500">No attendance records are available yet.</td></tr>@endforelse</tbody></table>
        </div>
        {{ $records->links() }}
    </main>
</div>
@endsection
