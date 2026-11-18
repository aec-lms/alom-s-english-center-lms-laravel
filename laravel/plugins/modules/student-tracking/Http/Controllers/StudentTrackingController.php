<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentTracking\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\StudentTracking\Models\StudentActivityEvent;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;

class StudentTrackingController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $events = StudentActivityEvent::query()
            ->when($request->filled('student_id'), fn ($query) => $query->where('user_id', (int) $request->integer('student_id')))
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')->toString()))
            ->when($actor->hasRole('branch_manager'), function ($query) use ($actor): void {
                $query->whereIn('user_id', fn ($subquery) => $subquery->select('id')->from('users')->where('branch_id', $actor->branch_id));
            })
            ->latest('occurred_at')
            ->paginate(40)
            ->withQueryString();

        $summaryQuery = StudentActivityEvent::query()
            ->when($actor->hasRole('branch_manager'), function ($query) use ($actor): void {
                $query->whereIn('user_id', fn ($subquery) => $subquery->select('id')->from('users')->where('branch_id', $actor->branch_id));
            });

        $summary = [
            'active_students' => (clone $summaryQuery)->where('occurred_at', '>=', now()->subDays(7))->distinct('user_id')->count('user_id'),
            'events_today' => (clone $summaryQuery)->whereDate('occurred_at', today())->count(),
            'lessons_completed' => (clone $summaryQuery)->where('event_type', 'lesson_completed')->where('occurred_at', '>=', now()->subDays(30))->count(),
            'quiz_submissions' => (clone $summaryQuery)->where('event_type', 'quiz_submitted')->where('occurred_at', '>=', now()->subDays(30))->count(),
        ];

        return view('student-tracking::index', compact('events', 'summary'));
    }

    public function mine(Request $request): View
    {
        $events = StudentActivityEvent::query()->where('user_id', $request->user()->id)->latest('occurred_at')->paginate(30);
        return view('student-tracking::mine', compact('events'));
    }

    public function guardian(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('guardian'), 403, 'Guardian accounts only.');
        abort_unless(
            app(PluginManager::class)->isEnabled('guardian-management')
                && Schema::hasTable('guardians')
                && Schema::hasTable('guardian_student')
                && Schema::hasTable('students'),
            404,
            'Guardian-to-student linking is not enabled.',
        );

        $guardian = DB::table('guardians')->where('user_id', $actor->id)->first();
        abort_unless($guardian, 404, 'Guardian profile not found.');

        $linkedStudents = DB::table('guardian_student as gs')
            ->join('students as s', 's.id', '=', 'gs.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('gs.guardian_id', $guardian->id)
            ->select(['s.id as student_id', 's.user_id', 'u.name as student_name'])
            ->orderBy('u.name')
            ->get();

        $events = StudentActivityEvent::query()
            ->whereIn('user_id', $linkedStudents->pluck('user_id')->all())
            ->latest('occurred_at')
            ->paginate(30);

        return view('student-tracking::guardian', compact('events', 'linkedStudents'));
    }

    public function record(Request $request, StudentActivityTracker $tracker): JsonResponse
    {
        abort_unless($request->user()->hasRole('student'), 403, 'Only student accounts can submit their own learning activity.');
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:lesson_started,lesson_completed'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'string', 'max:100'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:21600'],
            'metadata' => ['nullable', 'array'],
        ]);

        $metadata = $validated['metadata'] ?? [];
        if (app(PluginManager::class)->isEnabled('course-management')) {
            abort_unless(
                Schema::hasTable('course_lessons')
                    && Schema::hasTable('courses')
                    && Schema::hasTable('enrollments'),
                409,
                'Course lesson tracking is unavailable until the course tables are installed.'
            );
            abort_unless(
                ($validated['subject_type'] ?? null) === 'lesson'
                    && filter_var($validated['subject_id'] ?? null, FILTER_VALIDATE_INT) !== false
                    && (int) ($validated['subject_id'] ?? 0) > 0,
                422,
                'A valid course lesson is required for learning activity tracking.'
            );

            $lesson = DB::table('course_lessons as lessons')
                ->join('courses', 'courses.id', '=', 'lessons.course_id')
                ->where('lessons.id', (int) $validated['subject_id'])
                ->where('courses.status', 'published')
                ->first([
                    'lessons.id',
                    'lessons.course_id',
                    'lessons.title as lesson_title',
                    'courses.title as course_title',
                ]);
            abort_unless($lesson, 404, 'The requested published lesson was not found.');

            abort_unless(
                DB::table('enrollments')
                    ->where('user_id', $request->user()->id)
                    ->where('course_id', $lesson->course_id)
                    ->whereIn('status', ['active', 'completed'])
                    ->exists(),
                403,
                'An active course enrollment is required to record this lesson activity.'
            );

            // Client-supplied titles and analytics are not trusted when the LMS
            // can resolve the canonical course and lesson records.
            $metadata = [
                'course_title' => $lesson->course_title,
                'lesson_title' => $lesson->lesson_title,
                'source' => 'student_portal',
            ];
        }

        $event = $tracker->record(
            (int) $request->user()->id,
            $validated['event_type'],
            $validated['subject_type'] ?? null,
            $validated['subject_id'] ?? null,
            $validated['duration_seconds'] ?? null,
            $metadata,
        );

        return response()->json(['recorded' => true, 'event_id' => $event->id], 201);
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null
            || !app(PluginManager::class)->isEnabled('branch-management')
            || !Schema::hasTable('branches')
            || !app('db')->table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists()) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
    }
}
