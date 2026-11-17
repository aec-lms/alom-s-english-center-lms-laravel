@extends('layouts.admin')
@section('title', 'Student Tracking & Analytics — AEC LMS')
@section('content')
<div class="space-y-6 max-w-7xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Student Tracking & Analytics</h1>
        <p class="text-sm text-slate-500 mt-1">Learning activity timeline and engagement signals, limited to LMS learning events.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        @foreach (['active_students' => 'Active students (7 days)', 'events_today' => 'Events today', 'lessons_completed' => 'Lessons completed (30 days)', 'quiz_submissions' => 'Quiz submissions (30 days)'] as $key => $label)
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="text-2xl font-bold text-slate-900 mt-2">{{ number_format($summary[$key]) }}</p>
            </div>
        @endforeach
    </div>
    <div class="bg-white border border-slate-200 rounded-2xl p-5 overflow-x-auto">
        <form method="GET" class="flex flex-wrap gap-3 mb-4">
            <input name="student_id" value="{{ request('student_id') }}" placeholder="Student user ID" class="border rounded-lg px-3 py-2 text-sm">
            <select name="event_type" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All activity types</option>
                @foreach (['login','lesson_started','lesson_completed','quiz_submitted','live_class_joined','attendance_marked','course_enrolled','certificate_earned'] as $type)
                    <option value="{{ $type }}" @selected(request('event_type') === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-slate-900 text-white px-4 py-2 text-sm">Filter</button>
        </form>
        <table class="w-full text-sm text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">When</th><th>Student ID</th><th>Activity</th><th>Course / Lesson</th><th>Duration</th><th>Details</th></tr></thead>
            <tbody class="divide-y">
            @forelse ($events as $event)
                <tr>
                    <td class="py-3 whitespace-nowrap">{{ $event->occurred_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $event->user_id }}</td>
                    <td class="font-semibold">{{ str_replace('_', ' ', ucfirst($event->event_type)) }}</td>
                    <td>{{ $event->metadata['course_title'] ?? $event->metadata['lesson_title'] ?? '—' }}</td>
                    <td>{{ $event->duration_seconds ? gmdate('H:i:s', $event->duration_seconds) : '—' }}</td>
                    <td>{{ $event->metadata['result_percent'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-6 text-center text-slate-400">No student activity recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $events->links() }}</div>
    </div>
</div>
@endsection
