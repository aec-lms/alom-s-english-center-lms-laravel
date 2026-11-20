<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\CourseAssessments\Models\AssessmentAttempt;
use Plugins\Modules\CourseAssessments\Models\AssessmentSet;

class AssessmentGradingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $this->ensureBranchAccess($actor);
        $attempts = AssessmentAttempt::query()->with(['questions', 'assessmentSet'])
            ->whereIn('status', ['pending_review', 'graded'])
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->whereHas('assessmentSet', fn ($s) => $s->where('branch_id', (int) $actor->branch_id)))
            ->when($actor->hasRole('teacher'), function ($q) use ($actor): void {
                $profile = $this->teacherProfile($actor, false);
                abort_unless($profile && (bool) $profile->is_active, 403, 'An active teacher profile is required.');
                $this->assertTeacherBranchConsistency($actor, $profile);
                abort_unless(Schema::hasTable('course_teacher_assignments') && Schema::hasTable('courses'), 403, 'Teacher course assignments are required.');

                $branchId = $profile->branch_id === null ? null : (int) $profile->branch_id;
                $q->whereHas('assessmentSet', function ($setQuery) use ($actor, $branchId): void {
                    if ($branchId === null) {
                        $setQuery->whereNull('branch_id');
                    } else {
                        $setQuery->where('branch_id', $branchId);
                    }

                    $setQuery->whereIn('course_id', function ($courseQuery) use ($branchId): void {
                        $courseQuery->select('id')->from('courses');
                        if ($branchId === null) {
                            $courseQuery->whereNull('branch_id');
                        } else {
                            $courseQuery->where('branch_id', $branchId);
                        }
                    })->whereIn('course_id', DB::table('course_teacher_assignments')
                        ->select('course_id')->where('teacher_user_id', $actor->id));
                });
            })
            ->latest('submitted_at')->paginate(25);

        return view('course-assessments::grading', compact('attempts'));
    }

    public function grade(Request $request, AssessmentAttempt $attempt): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.*' => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'array'],
            'feedback.*' => ['nullable', 'string', 'max:5000'],
            'teacher_feedback' => ['nullable', 'string', 'max:10000'],
        ]);

        $manual = 0.0;
        DB::transaction(function () use ($attempt, $data, $actor, &$manual): void {
            $locked = AssessmentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['pending_review', 'graded'], true), 409, 'Attempt is not awaiting manual grading.');

            $set = AssessmentSet::query()->whereKey($locked->assessment_set_id)->lockForUpdate()->firstOrFail();
            $course = null;
            if ($set->course_id !== null) {
                abort_unless(Schema::hasTable('courses'), 403, 'Course records are unavailable.');
                $course = DB::table('courses')->where('id', $set->course_id)->lockForUpdate()->first();
                abort_unless($course, 404, 'The linked course no longer exists.');
                abort_unless($set->branch_id === null
                    ? $course->branch_id === null
                    : (int) $course->branch_id === (int) $set->branch_id, 403, 'Assessment and course branch ownership do not match.');
            }

            $this->authorizeGrader($actor, $set, $course);

            foreach ($locked->questions()->whereIn('type', ['short_answer', 'written'])->lockForUpdate()->get() as $question) {
                $score = (float) ($data['scores'][$question->id] ?? 0);
                abort_if($score > (float) $question->marks, 422, 'Awarded score exceeds question marks.');
                $question->update([
                    'marks_awarded' => $score,
                    'manual_feedback' => $data['feedback'][$question->id] ?? null,
                    'graded_by' => $actor->id,
                    'graded_at' => now(),
                ]);
                $manual += $score;
            }
            $locked->update([
                'manual_score' => $manual,
                'final_score' => (float) $locked->auto_score + $manual,
                'status' => 'graded',
                'graded_by' => $actor->id,
                'graded_at' => now(),
                'teacher_feedback' => $data['teacher_feedback'] ?? null,
            ]);
        });

        $this->audit->log('course_assessment.graded', 'course_assessment_attempt', (string) $attempt->id, ['manual_score' => $manual]);

        return back()->with('status', 'Assessment grading saved.');
    }

    private function authorizeGrader($actor, AssessmentSet $set, ?object $course): void
    {
        if ($actor->hasRole('admin')) {
            return;
        }

        if ($actor->hasRole('branch_manager')) {
            $this->ensureBranchAccess($actor);
            abort_unless((int) $set->branch_id === (int) $actor->branch_id, 403, 'Branch isolation violation.');
            if ($course !== null) {
                abort_unless((int) $course->branch_id === (int) $actor->branch_id, 403, 'Course branch isolation violation.');
            }
            return;
        }

        abort_unless($actor->hasRole('teacher'), 403);
        $profile = $this->teacherProfile($actor, true);
        abort_unless($profile && (bool) $profile->is_active, 403, 'An active teacher profile is required.');
        $this->assertTeacherBranchConsistency($actor, $profile);

        $branchId = $profile->branch_id === null ? null : (int) $profile->branch_id;
        abort_unless($set->branch_id === null
            ? $branchId === null
            : $branchId !== null && (int) $set->branch_id === $branchId, 403, 'Assessment belongs to another branch.');

        if ($course !== null) {
            abort_unless($course->branch_id === null
                ? $branchId === null
                : $branchId !== null && (int) $course->branch_id === $branchId, 403, 'Course belongs to another branch.');
        }

        abort_unless(Schema::hasTable('course_teacher_assignments'), 403, 'Teacher course assignments are required.');
        $assignment = DB::table('course_teacher_assignments')
            ->where('course_id', $set->course_id)
            ->where('teacher_user_id', $actor->id)
            ->lockForUpdate()
            ->first();
        abort_unless($set->course_id !== null && $assignment, 403, 'You are not assigned to this course.');
    }

    private function assertTeacherBranchConsistency($actor, object $profile): void
    {
        if (Schema::hasColumn('users', 'branch_id') && $actor->branch_id !== null) {
            abort_unless($profile->branch_id !== null
                && (int) $profile->branch_id === (int) $actor->branch_id, 403, 'Teacher account and profile branch assignments do not match.');
        }
    }

    private function teacherProfile($actor, bool $lock): ?object
    {
        if (Schema::hasTable('teachers')) {
            $query = DB::table('teachers')->where('user_id', $actor->id);
            if (Schema::hasColumn('teachers', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            if (Schema::hasColumn('teachers', 'is_active')) {
                $query->where('is_active', true);
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            $profile = $query->first();
            if (!$profile) {
                return null;
            }

            $profile->is_active = Schema::hasColumn('teachers', 'is_active') ? (bool) $profile->is_active : true;
            return $profile;
        }

        if (!Schema::hasColumn('users', 'branch_id')) {
            return null;
        }

        return (object) [
            'branch_id' => $actor->branch_id,
            'is_active' => true,
        ];
    }

    private function ensureBranchAccess($actor): void
    {
        if (!$actor?->hasRole('branch_manager')) return;

        abort_unless(
            $actor->branch_id
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }
}
