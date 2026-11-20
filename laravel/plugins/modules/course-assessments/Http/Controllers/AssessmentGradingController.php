<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
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
                $this->applyTeacherAssessmentScope($q, $actor);
            })
            ->latest('submitted_at')->paginate(25);

        return view('course-assessments::grading', compact('attempts'));
    }

    public function grade(Request $request, AssessmentAttempt $attempt): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor, 401);

        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.*' => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'array'],
            'feedback.*' => ['nullable', 'string', 'max:5000'],
            'teacher_feedback' => ['nullable', 'string', 'max:10000'],
        ]);

        $manual = 0.0;
        DB::transaction(function () use ($attempt, $data, $actor, &$manual): void {
            // Lock the reviewer first, then the attempt, assessment and course.
            // This re-reads the reviewer's current branch and assignment while
            // serializing the final authorization decision with assignment removal.
            $reviewer = User::query()->lockForUpdate()->findOrFail($actor->id);
            $locked = AssessmentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['pending_review', 'graded'], true), 409, 'Attempt is not awaiting manual grading.');
            $set = AssessmentSet::query()->whereKey($locked->assessment_set_id)->lockForUpdate()->firstOrFail();
            $course = $set->course_id !== null
                ? DB::table('courses')->where('id', $set->course_id)->lockForUpdate()->first()
                : null;
            $this->authorizeGrading($reviewer, $set, $course);
            foreach ($locked->questions()->whereIn('type', ['short_answer', 'written'])->get() as $question) {
                $score = (float) ($data['scores'][$question->id] ?? 0);
                abort_if($score > (float) $question->marks, 422, 'Awarded score exceeds question marks.');
                $question->update([
                    'marks_awarded' => $score,
                    'manual_feedback' => $data['feedback'][$question->id] ?? null,
                    'graded_by' => $reviewer->id,
                    'graded_at' => now(),
                ]);
                $manual += $score;
            }
            $locked->update([
                'manual_score' => $manual,
                'final_score' => (float) $locked->auto_score + $manual,
                'status' => 'graded',
                'graded_by' => $reviewer->id,
                'graded_at' => now(),
                'teacher_feedback' => $data['teacher_feedback'] ?? null,
            ]);
        });

        $this->audit->log('course_assessment.graded', 'course_assessment_attempt', (string) $attempt->id, ['manual_score' => $manual]);

        return back()->with('status', 'Assessment grading saved.');
    }
    private function applyTeacherAssessmentScope($query, User $teacher): void
    {
        abort_unless(
            Schema::hasTable('course_teacher_assignments')
                && Schema::hasTable('courses')
                && Schema::hasColumn('courses', 'branch_id')
                && Schema::hasColumn('course_assessment_sets', 'branch_id'),
            403,
            'Teacher assessment branch scope cannot be verified.'
        );

        $branchId = $teacher->branch_id !== null ? (int) $teacher->branch_id : null;

        $query->whereIn('assessment_set_id', function ($sets) use ($teacher, $branchId): void {
            $sets->select('sets.id')
                ->from('course_assessment_sets as sets')
                ->join('courses as courses', 'courses.id', '=', 'sets.course_id')
                ->whereIn('sets.course_id', function ($assignments) use ($teacher): void {
                    $assignments->select('course_id')
                        ->from('course_teacher_assignments')
                        ->where('teacher_user_id', $teacher->id);
                })
                // A set may inherit its branch from its course, but conflicting
                // explicit ownership is always rejected.
                ->where(function ($scope): void {
                    $scope->whereNull('sets.branch_id')
                        ->orWhereColumn('sets.branch_id', 'courses.branch_id');
                })
                ->when(
                    $branchId === null,
                    fn ($scope) => $scope->whereNull('sets.branch_id')->whereNull('courses.branch_id'),
                    fn ($scope) => $scope->where('courses.branch_id', $branchId)
                );
        });
    }

    private function authorizeGrading(User $reviewer, AssessmentSet $set, ?object $course): void
    {
        if ($set->course_id !== null) {
            abort_unless($course, 404, 'The linked course no longer exists.');
            abort_unless(
                $set->branch_id === null || (int) $set->branch_id === (int) $course->branch_id,
                403,
                'Assessment and course branch ownership is inconsistent.'
            );
        }

        if ($reviewer->hasRole('admin')) {
            return;
        }

        if ($reviewer->hasRole('branch_manager')) {
            $this->ensureBranchAccess($reviewer);
            abort_unless(
                (int) $set->branch_id === (int) $reviewer->branch_id
                    && ($course === null || (int) $course->branch_id === (int) $reviewer->branch_id),
                403,
                'Branch isolation violation.'
            );

            return;
        }

        abort_unless($reviewer->hasRole('teacher'), 403);

        // Branch-assigned teachers may only grade assessments whose linked
        // course belongs to that branch. Unassigned teachers are global-only.
        if ($reviewer->branch_id !== null) {
            abort_unless(
                $course !== null
                    && $course->branch_id !== null
                    && (int) $course->branch_id === (int) $reviewer->branch_id
                    && ($set->branch_id === null || (int) $set->branch_id === (int) $reviewer->branch_id),
                403,
                'Teacher branch does not match the assessment and course.'
            );
        } else {
            abort_unless(
                $set->branch_id === null && ($course === null || $course->branch_id === null),
                403,
                'Global teachers may only grade global assessments.'
            );
        }

        abort_unless($set->course_id !== null && Schema::hasTable('course_teacher_assignments'), 403);
        $assigned = DB::table('course_teacher_assignments')
            ->where('course_id', $set->course_id)
            ->where('teacher_user_id', $reviewer->id)
            ->lockForUpdate()
            ->exists();
        abort_unless($assigned, 403, 'You are not assigned to this course.');
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
