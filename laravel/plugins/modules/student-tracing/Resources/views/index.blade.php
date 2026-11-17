<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Student Activity Trace</h1>
                <p class="text-sm text-gray-600">Branch-scoped student lifecycle history. Personal profile values are not copied into trace metadata.</p>
            </div>
            <a href="{{ route('plugins.student-tracing.export', request()->query()) }}" class="rounded bg-slate-900 px-4 py-2 text-white">Export CSV</a>
        </div>

        <form method="GET" class="grid gap-3 rounded border p-4 md:grid-cols-4">
            <label class="text-sm">Student ID
                <input name="student_id" value="{{ request('student_id') }}" inputmode="numeric" class="mt-1 w-full rounded border-gray-300">
            </label>
            <label class="text-sm">Event
                <select name="event_type" class="mt-1 w-full rounded border-gray-300">
                    <option value="">All events</option>
                    @foreach (['student.created' => 'Student created', 'student.updated' => 'Student updated', 'student.deleted' => 'Student deleted', 'attendance.created' => 'Attendance recorded', 'attendance.updated' => 'Attendance updated', 'attendance.deleted' => 'Attendance deleted', 'enrollment.created' => 'Enrollment created', 'enrollment.updated' => 'Enrollment updated', 'enrollment.deleted' => 'Enrollment deleted', 'exam_attempt.created' => 'Exam attempt created', 'exam_attempt.updated' => 'Exam attempt updated', 'exam_attempt.deleted' => 'Exam attempt deleted'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('event_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">From
                <input type="date" name="from" value="{{ request('from') }}" class="mt-1 w-full rounded border-gray-300">
            </label>
            <label class="text-sm">To
                <input type="date" name="to" value="{{ request('to') }}" class="mt-1 w-full rounded border-gray-300">
            </label>
            <div class="md:col-span-4"><button class="rounded border px-4 py-2">Filter activity</button></div>
        </form>

        <div class="rounded border">
            <div class="border-b p-4 text-sm font-medium">Matching events: {{ number_format($total) }}</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead><tr class="border-b bg-gray-50"><th class="p-3">Time</th><th class="p-3">Student</th><th class="p-3">Branch</th><th class="p-3">Actor</th><th class="p-3">Activity</th><th class="p-3">Changed fields</th></tr></thead>
                    <tbody>
                    @forelse ($events as $event)
                        @php($metadata = json_decode((string) $event->metadata, true) ?: [])
                        <tr class="border-b">
                            <td class="p-3 whitespace-nowrap">{{ $event->occurred_at }}</td>
                            <td class="p-3">#{{ $event->student_id }}</td>
                            <td class="p-3">{{ $event->branch_id ?? '—' }}</td>
                            <td class="p-3">{{ $event->actor_id ? '#'.$event->actor_id : 'System' }}</td>
                            <td class="p-3">{{ $event->event_type }}</td>
                            <td class="p-3">{{ implode(', ', $metadata['changed_fields'] ?? []) ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-6 text-center text-gray-500">No activity events found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($events, 'links'))
                <div class="p-4">{{ $events->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
