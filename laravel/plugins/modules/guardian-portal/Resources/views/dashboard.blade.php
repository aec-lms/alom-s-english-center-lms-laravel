@extends('layouts.app')

@section('title', 'Guardian Portal — Alom’s English Center')

@section('content')
<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">Alom’s English Center</p>
            <h1 class="mt-1 text-3xl font-extrabold text-slate-900">Guardian Portal</h1>
            <p class="mt-2 text-sm text-slate-600">A private, read-only view of the students linked to your guardian account.</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
            <span class="text-slate-500">Signed in as</span>
            <div class="font-semibold text-slate-900">{{ auth()->user()->name }}</div>
        </div>
    </header>

    @unless ($integrations['guardian'])
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            Guardian Management and Student Management are not both enabled yet. Your portal is available, but linked student information will appear after the integrations are enabled and your relationship is verified.
        </section>
    @else
        @if ($children->isEmpty())
            <section class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                <h2 class="text-lg font-bold text-slate-900">No linked students yet</h2>
                <p class="mt-2 text-sm text-slate-600">Contact the Alom’s English Center administration to verify and link your student account.</p>
            </section>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Your linked students</h2>
                        <p class="text-sm text-slate-500">Choose a child to view their individual records.</p>
                    </div>
                    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">{{ $children->count() }} linked</span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($children as $child)
                        <a href="{{ route('plugins.guardian-portal.dashboard', ['student' => $child->student_id]) }}"
                           class="rounded-xl border px-4 py-3 text-sm {{ $selected && (int) $selected->student_id === (int) $child->student_id ? 'border-indigo-600 bg-indigo-50 text-indigo-800' : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300' }}">
                            <span class="block font-semibold">{{ $child->student_name }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $child->student_code }} · {{ ucfirst($child->student_status) }}</span>
                        </a>
                    @endforeach
                </div>
            </section>

            @if ($selected)
                <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Enrolled courses</p><p class="mt-2 text-2xl font-extrabold text-slate-900">{{ $summary['courses'] }}</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Learning activity · 30 days</p><p class="mt-2 text-2xl font-extrabold text-slate-900">{{ $summary['activity_count'] }}</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Attendance records shown</p><p class="mt-2 text-2xl font-extrabold text-slate-900">{{ $summary['attendance_total'] }}</p><p class="mt-1 text-xs text-slate-500">{{ $summary['attendance_present'] }} present · {{ $summary['attendance_absent'] }} absent · {{ $summary['attendance_late'] }} late</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Outstanding fees</p><p class="mt-2 text-2xl font-extrabold text-slate-900">৳{{ number_format($summary['unpaid_amount'], 2) }}</p></div>
                </section>

                <section class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="font-bold text-slate-900">Courses</h2>
                        <div class="mt-3 divide-y divide-slate-100">
                            @forelse ($courses as $course)
                                <div class="flex items-center justify-between gap-3 py-3"><span class="font-medium text-slate-800">{{ $course->title }}</span><span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ ucfirst($course->enrollment_status) }}</span></div>
                            @empty
                                <p class="py-4 text-sm text-slate-500">No course enrolments available, or Course Management is disabled.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="font-bold text-slate-900">Exam results</h2>
                        <p class="mt-1 text-sm text-slate-500">Average score: {{ $summary['average_score'] === null ? '—' : $summary['average_score'].'%' }}</p>
                        <div class="mt-3 divide-y divide-slate-100">
                            @forelse ($examResults as $result)
                                <div class="flex items-center justify-between gap-3 py-3"><div><p class="font-medium text-slate-800">{{ $result->exam_title }}</p><p class="text-xs text-slate-500">{{ $result->submitted_at }}</p></div><span class="text-sm font-semibold">{{ number_format((float) $result->score_obtained, 2) }} / {{ number_format((float) $result->total_marks, 2) }} · {{ $result->passed ? 'Passed' : 'Not passed' }}</span></div>
                            @empty
                                <p class="py-4 text-sm text-slate-500">No exam results available, or Exam Management is disabled.</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="font-bold text-slate-900">Attendance</h2>
                        <div class="mt-3 divide-y divide-slate-100">
                            @forelse ($attendance as $record)
                                <div class="flex items-center justify-between gap-3 py-3"><span class="text-sm text-slate-700">{{ $record->attendance_date }}</span><span class="text-sm font-semibold capitalize">{{ $record->status }}</span></div>
                            @empty
                                <p class="py-4 text-sm text-slate-500">No attendance records available, or Attendance Management is disabled.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="font-bold text-slate-900">Recent learning activity</h2>
                        <div class="mt-3 divide-y divide-slate-100">
                            @forelse ($activities as $activity)
                                <div class="py-3"><p class="text-sm font-medium text-slate-800">{{ data_get(is_array($activity->metadata) ? $activity->metadata : json_decode($activity->metadata ?? '{}', true), 'lesson_title', data_get(is_array($activity->metadata) ? $activity->metadata : json_decode($activity->metadata ?? '{}', true), 'course_title', str($activity->event_type)->replace('_', ' ')->title())) }}</p><p class="text-xs text-slate-500">{{ $activity->occurred_at }} · {{ $activity->duration_seconds ? gmdate('H:i:s', $activity->duration_seconds) : '—' }}</p></div>
                            @empty
                                <p class="py-4 text-sm text-slate-500">No recent learning activity available, or Student Tracking is disabled.</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="font-bold text-slate-900">Fee invoices</h2>
                        <div class="mt-3 divide-y divide-slate-100">
                            @forelse ($invoices as $invoice)
                                <div class="flex items-center justify-between gap-3 py-3"><div><p class="text-sm font-medium text-slate-800">{{ $invoice->invoice_number }}</p><p class="text-xs text-slate-500">Due: {{ $invoice->due_date ?: '—' }}</p></div><span class="text-sm font-semibold">৳{{ number_format((float) $invoice->net_amount, 2) }} · {{ ucfirst($invoice->status) }}</span></div>
                            @empty
                                <p class="py-4 text-sm text-slate-500">No fee invoices available, or Fees Management is disabled.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h2 class="font-bold text-slate-900">Notices</h2>
                        <div class="mt-3 divide-y divide-slate-100">
                            @forelse ($notices as $notice)
                                <article class="py-3"><h3 class="font-semibold text-slate-800">{{ $notice->title }}</h3><p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $notice->body }}</p><p class="mt-2 text-xs text-slate-400">{{ $notice->published_at }}</p></article>
                            @empty
                                <p class="py-4 text-sm text-slate-500">No published notices available, or Notice Board is disabled.</p>
                            @endforelse
                        </div>
                    </div>
                </section>
            @endif
        @endif
    @endunless
</div>
@endsection
