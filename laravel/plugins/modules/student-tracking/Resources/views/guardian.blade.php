@extends('layouts.app')
@section('title', 'Linked Student Learning Activity — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6 px-4 py-8">
    <header>
        <h1 class="text-2xl font-bold text-slate-900">Linked Student Learning Activity</h1>
        <p class="mt-1 text-sm text-slate-600">A read-only learning timeline for students linked to your guardian account.</p>
    </header>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Linked students</h2>
        @forelse ($linkedStudents as $student)
            <div class="mt-3 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                <span class="font-medium text-slate-900">{{ $student->student_name }}</span>
                <span class="ml-2 text-slate-500">Student #{{ $student->student_id }}</span>
            </div>
        @empty
            <p class="mt-3 text-sm text-slate-500">No student has been linked to this guardian account yet.</p>
        @endforelse
    </section>

    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Recent learning activity</h2>
        <table class="mt-4 w-full text-left text-sm">
            <thead class="border-b text-slate-500">
                <tr><th class="py-2">When</th><th>Activity</th><th>Course / Lesson</th><th>Duration</th><th>Result</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($events as $event)
                    <tr>
                        <td class="py-3 whitespace-nowrap">{{ $event->occurred_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ str_replace('_', ' ', ucfirst($event->event_type)) }}</td>
                        <td>{{ $event->metadata['course_title'] ?? $event->metadata['lesson_title'] ?? '—' }}</td>
                        <td>{{ $event->duration_seconds ? gmdate('H:i:s', $event->duration_seconds) : '—' }}</td>
                        <td>{{ $event->metadata['result_percent'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-slate-500">No learning activity is available for linked students.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $events->links() }}</div>
    </section>
</div>
@endsection
