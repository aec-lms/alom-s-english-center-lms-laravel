@php
    $progressCourses = collect();
    $schema = \\Illuminate\\Support\\Facades\\Schema::class;
    if ($schema::hasTable('courses') && $schema::hasTable('course_lessons') && $schema::hasTable('enrollments') && $schema::hasTable('student_activity_events')) {
        $courses = \\Illuminate\\Support\\Facades\\DB::table('courses')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->limit(8)
            ->get(['id', 'title']);
        foreach ($courses as $course) {
            $lessonIds = \\Illuminate\\Support\\Facades\\DB::table('course_lessons')->where('course_id', $course->id)->pluck('id')->map(fn ($id) => (string) $id);
            $studentIds = \\Illuminate\\Support\\Facades\\DB::table('enrollments')->where('course_id', $course->id)->whereIn('status', ['active', 'completed'])->distinct()->pluck('user_id');
            $lessonCount = $lessonIds->count();
            $enrollmentCount = $studentIds->count();
            if ($lessonCount === 0 || $enrollmentCount === 0) {
                continue;
            }
            $completedPairs = collect();
            if ($studentIds->isNotEmpty()) {
                $completedPairs = \\Illuminate\\Support\\Facades\\DB::table('student_activity_events')
                    ->whereIn('user_id', $studentIds)
                    ->where('event_type', 'lesson_completed')
                    ->where('subject_type', 'lesson')
                    ->whereIn('subject_id', $lessonIds)
                    ->get(['user_id', 'subject_id'])
                    ->map(fn ($event) => $event->user_id . ':' . $event->subject_id)
                    ->unique();
            }
            $denominator = $lessonCount * $enrollmentCount;
            $progressCourses->push((object) [
                'title' => $course->title,
                'enrollments' => $enrollmentCount,
                'completed_lessons' => $completedPairs->count(),
                'progress' => min(100, (int) round(($completedPairs->count() / $denominator) * 100)),
            ]);
        }
        $progressCourses = $progressCourses->sortByDesc('progress')->take(5)->values();
    }
@endphp
<section class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div><h2 class="text-sm font-bold text-slate-900">Course Learning Progress</h2><p class="mt-1 text-xs text-slate-500">Completion based on recorded lesson-completed events</p></div>
        <a href="{{ route('plugins.student-tracking.admin.index') }}" class="text-xs font-semibold text-indigo-700 hover:underline">Activity reports</a>
    </div>
    @forelse ($progressCourses as $course)
        <div class="border-t border-slate-100 py-3">
            <div class="mb-2 flex items-center justify-between gap-3"><p class="truncate text-sm font-semibold text-slate-800">{{ $course->title }}</p><span class="text-xs font-bold tabular-nums text-slate-700">{{ $course->progress }}%</span></div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-600" style="width: {{ $course->progress }}%"></div></div>
            <p class="mt-1 text-[11px] text-slate-500">{{ $course->enrollments }} active/completed enrollments · {{ $course->completed_lessons }} completed lesson records</p>
        </div>
    @empty
        <p class="border-t border-slate-100 py-5 text-sm text-slate-500">Progress will appear when published courses have enrollments, lessons and recorded completion activity.</p>
    @endforelse
</section>
