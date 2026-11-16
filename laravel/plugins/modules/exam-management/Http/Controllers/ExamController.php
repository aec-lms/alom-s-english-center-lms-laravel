<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Plugins\Modules\StudentManagement\Models\Student;

class ExamController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $exams = Exam::query()
            ->withCount(['questions', 'attempts'])
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(20);
        $courses = app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses')
            ? DB::table('courses')->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))->orderBy('title')->get()
            : collect();

        return view('exam-management::index', compact('exams', 'courses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $courseIntegration = app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses');
        $branchIntegration = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $courseRule = $courseIntegration
            ? ['nullable', 'integer', 'exists:courses,id']
            : ['nullable', 'integer'];
        $branchRule = $branchIntegration
            ? ['nullable', 'integer', 'exists:branches,id,is_active,1']
            : ['nullable', 'integer'];

        $validated = $request->validate([
            'course_id' => $courseRule,
            'branch_id' => $branchRule,
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'integer', 'min:1'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $courseId = $courseIntegration && !empty($validated['course_id']) && (int) $validated['course_id'] > 0
            ? (int) $validated['course_id']
            : null;
        $course = $courseId !== null ? DB::table('courses')->where('id', $courseId)->first() : null;
        $branchId = $branchIntegration
            ? (isset($validated['branch_id'])
                ? (int) $validated['branch_id']
                : ($course && $course->branch_id !== null ? (int) $course->branch_id : null))
            : null;

        if ($courseId !== null && !$course) {
            abort(422, 'Selected course does not exist.');
        }
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($branchIntegration, 403, 'Branch integration is required for branch-scoped exams.');
            if ($course && (int) ($course->branch_id ?? 0) !== (int) $actor->branch_id) {
                abort(403, 'Course is outside your assigned branch.');
            }
            $branchId = (int) $actor->branch_id;
        }

        if ($course && $branchIntegration && (int) ($course->branch_id ?? 0) !== (int) ($branchId ?? 0)) {
            abort(422, 'Exam and course must belong to the same branch.');
        }

        $exam = Exam::query()->create([
            'course_id' => $courseId,
            'branch_id' => $branchId,
            'title' => $validated['title'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'pass_marks' => (int) $validated['pass_marks'],
            'is_published' => $request->boolean('is_published', false),
        ]);

        $this->audit->log('exam.created', 'exam', (string) $exam->id, ['title' => $exam->title]);

        return back()->with('status', "Exam '{$exam->title}' created.");
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $courseIntegration = app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses');
        $branchIntegration = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $courseRule = $courseIntegration
            ? ['nullable', 'integer', 'exists:courses,id']
            : ['nullable', 'integer'];

        $validated = $request->validate([
            'course_id' => $courseRule,
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'integer', 'min:1'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $courseId = $courseIntegration
            ? (array_key_exists('course_id', $validated) && !empty($validated['course_id']) && (int) $validated['course_id'] > 0
                ? (int) $validated['course_id']
                : $exam->course_id)
            : null;
        $course = $courseId !== null ? DB::table('courses')->where('id', $courseId)->first() : null;
        if ($courseId !== null && !$course) {
            abort(422, 'Selected course does not exist.');
        }

        $branchId = $branchIntegration ? $exam->branch_id : null;
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($branchIntegration, 403, 'Branch integration is required for branch-scoped exams.');
            $branchId = (int) $actor->branch_id;
        }
        if ($course && $branchIntegration && (int) ($course->branch_id ?? 0) !== (int) ($branchId ?? 0)) {
            abort(422, 'Exam and course must belong to the same branch.');
        }

        $exam->update([
            'course_id' => $courseId,
            'branch_id' => $branchId,
            'title' => $validated['title'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'pass_marks' => (int) $validated['pass_marks'],
            'is_published' => $request->boolean('is_published'),
        ]);

        $this->audit->log('exam.updated', 'exam', (string) $exam->id, ['title' => $exam->title]);

        return back()->with('status', "Exam '{$exam->title}' updated.");
    }

    public function destroy(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $title = $exam->title;
        $id = (string) $exam->id;
        $exam->delete();

        $this->audit->log('exam.trashed', 'exam', $id, ['title' => $title]);

        return back()->with('status', "Exam '{$title}' moved to the Admin Recycle Bin.");
    }

    public function addQuestion(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['required', 'string', 'max:300'],
            'correct_option_index' => ['required', 'integer', 'min:0'],
            'marks' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        if ((int) $validated['correct_option_index'] >= count($validated['options'])) {
            return back()->withErrors(['correct_option_index' => 'Correct option index is out of bounds.']);
        }

        ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => $validated['prompt'],
            'options' => array_values($validated['options']),
            'correct_option_index' => (int) $validated['correct_option_index'],
            'marks' => (int) $validated['marks'],
        ]);

        return back()->with('status', 'Question added to exam bank.');
    }

    /**
     * Server-side authoritative exam grading; never accepts client-computed scores.
     */
    public function submitAttempt(Request $request, Exam $exam): RedirectResponse
    {
        if (!$exam->is_published) {
            abort(403, 'Exam is not published for attempts.');
        }

        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can submit exam attempts.');

        if ($exam->starts_at && now()->lt($exam->starts_at)) {
            abort(403, 'This exam has not started yet.');
        }
        if ($exam->ends_at && now()->gt($exam->ends_at)) {
            abort(403, 'The exam submission window has closed.');
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(\Illuminate\Support\Facades\Schema::hasTable('learn_earn_exam_access') && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)->where('user_id', $actor->id)->where('status', 'granted')->exists(), 403, 'Payment is required before attempting this exam.');
        }

        if ($exam->branch_id !== null) {
            abort_unless(app(PluginManager::class)->isEnabled('student-management') && Schema::hasTable('students'), 403, 'Student Management must be enabled for branch-specific exam attempts.');
            $studentProfile = Student::query()->where('user_id', $actor->id)->first();
            abort_unless(
                $studentProfile && (int) $studentProfile->branch_id === (int) $exam->branch_id,
                403,
                'Exam is outside your assigned branch.'
            );
        }

        $questions = $exam->questions()->get();
        if ($questions->isEmpty()) {
            abort(422, 'This exam has no questions and cannot accept attempts.');
        }

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $allowedQuestionIds = $questions->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $submittedQuestionIds = array_map('strval', array_keys($validated['answers']));
        if (array_diff($submittedQuestionIds, $allowedQuestionIds) !== []) {
            abort(422, 'Answers contain a question that does not belong to this exam.');
        }

        $score = 0;
        $total = 0;
        $sanitizedAnswers = [];

        foreach ($questions as $question) {
            $total += (float) $question->marks;
            $selected = $validated['answers'][(string) $question->id] ?? null;
            $options = (array) $question->options;
            if ($selected !== null && (int) $selected >= count($options)) {
                abort(422, 'An answer option is outside the allowed range.');
            }
            if ($selected !== null && (int) $selected === (int) $question->correct_option_index) {
                $score += (float) $question->marks;
            } elseif ($selected !== null) {
                $score -= (float) ($question->negative_marks ?? 0);
            }
            $sanitizedAnswers[(string) $question->id] = $selected !== null ? (int) $selected : null;
        }

        $attempt = ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'user_id' => (int) $request->user()->id,
            'submitted_answers' => $sanitizedAnswers,
            'score_obtained' => $score,
            'total_marks' => $total,
            'passed' => $score >= (float) $exam->pass_marks,
            'submitted_at' => now(),
        ]);

        $this->audit->log('exam.attempted', 'exam_attempt', (string) $attempt->id, [
            'exam_id' => $exam->id,
            'score' => $score,
            'total' => $total,
        ]);

        return back()->with('status', "Exam graded server-side: {$score}/{$total} marks.");
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null
            || !app(PluginManager::class)->isEnabled('branch-management')
            || !Schema::hasTable('branches')
        ) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
        $activeBranch = app('db')->table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
