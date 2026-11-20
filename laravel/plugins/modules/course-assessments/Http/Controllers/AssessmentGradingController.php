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
                abort_unless(
                    Schema::hasTable('course_teacher_assignments') && Schema::hasTable('courses'),
                    403,
                    'Teacher course assignments and course records are required.'
                );
                $branchId = $this->teacherBranchId($actor);
                $q->whereHas('assessmentSet', function ($set) use ($actor, $branchId): void {
                    $set->whereIn(
                        'course_id',
                        DB::table('course_teacher_assignments')
                            ->select('course_id')
                            ->where('teacher_user_id', $actor->id)
                    )->whereExists(function ($course) use ($branchId): void {
                        $course->selectRaw('1')
                            ->from('courses')
                            ->whereColumn('courses.id', 'course_assessment_sets.course_id');
                        if ($branchId === null) {
                            $course->whereNull('courses.branch_id')
                                ->whereNull('course_assessment_sets.branch_id');
                        } else {
                            $course->where('courses.branch_id', $branchId)
                                ->where('course_assessment_sets.branch_id', $branchId);
                        }
                    });
                });
            })
            ->latest('submitted_at')->paginate(25);

        return view('course-assessments::grading', compact('attempts'));
    }

    public function grade(Request $request, AssessmentAttempt $attempt): RedirectResponse
    {
        $actor = $request->user();
        $attempt->load('assessmentSet');
        $set = $attempt->assessmentSet;
        abort_unless($set, 404);

        if ($actor->hasRole('branch_manager')) {
            $this->ensureBranchAccess($actor);
            abort_unless((int) $set->branch_id === (int) $actor->branch_id, 403, 'Branch isolation violation.');
        } elseif ($actor->hasRole('teacher')) {
            $this->authorizeTeacherAssessment($actor, $set);
        } else {
            abort_unless($actor->hasRole('admin'), 403);
        }

        abort_unless(in_array($attempt->status, ['pending_review', 'graded'], true), 409, 'Attempt is not awaiting manual grading.');
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
            $lockedSet = AssessmentSet::query()
                ->whereKey($locked->assessment_set_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($actor->hasRole('branch_manager')) {
                $this->ensureBranchAccess($actor);
                abort_unless((int) $lockedSet->branch_id === (int) $actor->branch_id, 403, 'Branch isolation violation.');
            } elseif ($actor->hasRole('teacher')) {
                // Re-check branch, course and assignment under locks immediately
                // before changing any marks or feedback.
                $this->authorizeTeacherAssessment($actor, $lockedSet, true);
            } else {
                abort_unless($actor->hasRole('admin'), 403);
            }
            foreach ($locked->questions()->whereIn('type', ['short_answer', 'written'])->get() as $question) {
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
    private function authorizeTeacherAssessment($actor, $set, bool $lock = false): void
    {
        abort_unless(
            $set->course_id !== null
                && Schema::hasTable('courses')
                && Schema::hasTable('course_teacher_assignments'),
            403,
            'A linked course and teacher assignment are required.'
        );

        $branchId = $this->teacherBranchId($actor, $lock);
        $courseQuery = DB::table('courses')->where('id', $set->course_id);
        if ($lock) {
            $courseQuery->lockForUpdate();
        }
        $course = $courseQuery->first();
        abort_unless($course, 403, 'The linked course is unavailable.');

        if ($branchId === null) {
            abort_unless(
                $set->branch_id === null && $course->branch_id === null,
                403,
                'Global teachers may only grade global assessments.'
            );
        } else {
            abort_unless(
                (int) $set->branch_id === $branchId && (int) $course->branch_id === $branchId,
                403,
                'Branch isolation violation.'
            );
        }

        $assignment = DB::table('course_teacher_assignments')
            ->where('course_id', $set->course_id)
            ->where('teacher_user_id', $actor->id);
        if ($lock) {
            $assignment->lockForUpdate();
        }
        abort_unless($assignment->first(), 403, 'You are not assigned to this course.');
    }

    private function teacherBranchId($actor, bool $lock = false): ?int
    {
        $profileBranchId = null;
        if (Schema::hasTable('teachers')) {
            $teacherQuery = DB::table('teachers')->where('user_id', $actor->id);
            if (Schema::hasColumn('teachers', 'deleted_at')) {
                $teacherQuery->whereNull('deleted_at');
            }
            if ($lock) {
                $teacherQuery->lockForUpdate();
            }
            $teacher = $teacherQuery->first();
            $profileBranchId = $teacher?->branch_id !== null ? (int) $teacher->branch_id : null;
        }

        $accountBranchId = $actor->branch_id !== null ? (int) $actor->branch_id : null;
        abort_if(
            $profileBranchId !== null && $accountBranchId !== null && $profileBranchId !== $accountBranchId,
            403,
            'Teacher profile and account branch assignments do not match.'
        );

        return $profileBranchId ?? $accountBranchId;
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
