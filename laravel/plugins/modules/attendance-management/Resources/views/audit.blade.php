@extends('layouts.admin')
@section('title', 'Attendance Audit History — AEC LMS')
@section('content')
<div class="max-w-7xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Attendance Audit History</h1>
            <p class="mt-1 text-sm text-slate-500">Review who changed attendance, when it changed, and the recorded reason.</p>
        </div>
        <a href="{{ route('plugins.attendance.index') }}" class="text-sm font-semibold text-sky-700 underline">← Attendance</a>
    </div>

    <form method="GET" action="{{ route('plugins.attendance.audit') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs font-semibold text-slate-600">From
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
        </label>
        <label class="text-xs font-semibold text-slate-600">To
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
        </label>
        <label class="text-xs font-semibold text-slate-600">Student ID
            <input type="number" min="1" name="student_id" value="{{ $filters['student_id'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
        </label>
        <label class="text-xs font-semibold text-slate-600">Actor user ID
            <input type="number" min="1" name="actor_id" value="{{ $filters['actor_id'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
        </label>
        <label class="text-xs font-semibold text-slate-600">Batch mapping ID
            <input type="number" min="1" name="batch_id" value="{{ $filters['batch_id'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
        </label>
        <label class="text-xs font-semibold text-slate-600">Source
            <select name="source" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">All sources</option>
                @foreach(['online', 'offline', 'qr'] as $source)
                    <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ ucfirst($source) }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs font-semibold text-slate-600">Event
            <select name="event" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">All events</option>
                @foreach(['created', 'updated', 'reconfirmed'] as $event)
                    <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ ucfirst($event) }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs font-semibold text-slate-600">Search
            <input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Student, actor, reason…" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
        </label>
        <div class="flex items-end gap-2 lg:col-span-4">
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">Apply filters</button>
            <a href="{{ route('plugins.attendance.audit') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Reset</a>
            <a href="{{ route('plugins.attendance.audit.export', request()->query()) }}" class="ml-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white">Export filtered CSV</a>
        </div>
    </form>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">{{ number_format($entries->total()) }} audit entries</div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Timestamp</th><th class="px-3 py-3">Student</th><th class="px-3 py-3">Batch</th>
                        <th class="px-3 py-3">Date</th><th class="px-3 py-3">Actor</th><th class="px-3 py-3">Change</th><th class="px-3 py-3">Source / Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-3">{{ \Illuminate\Support\Carbon::parse($entry->occurred_at)->format('d M Y, h:i A') }}</td>
                            <td class="px-3 py-3"><strong>{{ $entry->student_name }}</strong><br><span class="text-slate-500">{{ $entry->student_code }} · #{{ $entry->student_id }}</span><br><span class="text-slate-500">{{ $entry->student_email }}</span></td>
                            <td class="px-3 py-3">{{ $entry->batch_name ?? ('Batch #' . $entry->attendance_batch_mapping_id) }}</td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $entry->attendance_date }}</td>
                            <td class="px-3 py-3">{{ $entry->actor_name ?? 'System / deleted user' }}</td>
                            <td class="px-3 py-3"><span class="font-semibold">{{ ucfirst($entry->event) }}</span><br>{{ $entry->old_status ?? '—' }} → {{ $entry->new_status ?? '—' }}</td>
                            <td class="max-w-xs px-3 py-3"><span class="font-semibold">{{ ucfirst($entry->source) }}</span><br><span class="text-slate-600">{{ $entry->reason ?? 'No reason supplied' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No attendance audit entries match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-4">{{ $entries->links() }}</div>
    </section>
</div>
@endsection
