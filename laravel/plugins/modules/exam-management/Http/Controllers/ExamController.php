<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;

class ExamController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $courseIntegrationEnabled = app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management')
            && Schema::hasTable('courses');

        $exams = Exam::query()
            ->withCount(['questions', 'attempts'])
            ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id ?: -1))
            ->latest()
            ->paginate(20);

        $courses = $courseIntegrationEnabled
            ? DB::table('courses')
                ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id ?: -1))
                ->orderBy('title')
                ->get()
            : collect();

        return view('exam-management::index', compact('exams', 'courses', 'courseIntegrationEnabled'));
    }

        public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'numeric', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $course = null;
        if (!empty($validated['course_id'])) {
            abort_unless(app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management to attach an exam to a course.');
            $course = DB::table('courses')->where('id', (int) $validated['course_id'])->first();
            abort_unless($course, 422, 'Selected course does not exist.');
        }

        $resolvedBranchId = $actor && $actor->hasRole('branch_manager')
            ? (int) ($actor->branch_id ?? 0)
            : (isset($validated['branch_id']) ? (int) $validated['branch_id'] : ($course->branch_id ?? null));
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($resolvedBranchId > 0, 403, 'Your account is not assigned to an active branch.');
        }
        if ($course && (int) ($course->branch_id ?? 0) !== (int) ($resolvedBranchId ?? 0)) {
            abort(422, 'Exam and course must belong to the same branch.');
        }

        $exam = Exam::query()->create([
            'course_id' => $course?->id,
            'branch_id' => $resolvedBranchId,
            'title' => $validated['title'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'pass_marks' => (float) $validated['pass_marks'],
            'is_published' => $request->boolean('is_published', false),
        ]);

        $this->audit->log('exam.created', 'exam', (string) $exam->id, ['title' => $exam->title]);

        return back()->with('status', "Exam '{$exam->title}' created.");
    }

        public function update(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'numeric', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $courseId = array_key_exists('course_id', $validated)
            ? ($validated['course_id'] !== null ? (int) $validated['course_id'] : null)
            : ($exam->course_id !== null ? (int) $exam->course_id : null);
        $course = null;
        if ($courseId !== null) {
            abort_unless(app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management to attach an exam to a course.');
            $course = DB::table('courses')->where('id', $courseId)->first();
            abort_unless($course && (int) ($course->branch_id ?? 0) === (int) ($exam->branch_id ?? 0), 422, 'Exam and course must belong to the same branch.');
        }

        $exam->update([
            'course_id' => $courseId,
            'title' => $validated['title'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'pass_marks' => (float) $validated['pass_marks'],
            'is_published' => $request->boolean('is_published'),
        ]);

        $this->audit->log('exam.updated', 'exam', (string) $exam->id, ['title' => $exam->title]);

        return back()->with('status', "Exam '{$exam->title}' updated.");
    }

        public function destroy(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $title = $exam->title;
        $id = (string) $exam->id;
        $exam->delete();

        $this->audit->log('exam.deleted', 'exam', $id, ['title' => $title]);

        return back()->with('status', "Exam '{$title}' deleted.");
    }

    public function addQuestion(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
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
        if (\Illuminate\Support\Facades\Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(\Illuminate\Support\Facades\Schema::hasTable('learn_earn_exam_access') && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)->where('user_id', $actor?->id)->where('status', 'granted')->exists(), 403, 'Payment is required before attempting this exam.');
        }
        if ($exam->branch_id !== null && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Exam is outside your assigned branch.');
        }

        if ($exam->starts_at && now()->lt($exam->starts_at)) abort(403, 'This exam has not started yet.');
        if ($exam->ends_at && now()->gt($exam->ends_at)) abort(403, 'The exam submission window has closed.');
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $questions = $exam->questions()->get();
        abort_if($questions->isEmpty(), 422, 'This exam has no questions.');
        $allowedIds = $questions->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $submittedIds = array_map('strval', array_keys($validated['answers']));
        abort_if(array_diff($submittedIds, $allowedIds) !== [], 422, 'An answer references a question outside this exam.');
        $score = 0;
        $total = 0;
        $sanitizedAnswers = [];

        foreach ($questions as $question) {
            $total += (float) $question->marks;
            $selected = $validated['answers'][(string) $question->id] ?? null;
            $options = (array) $question->options;
            if ($selected !== null && (int) $selected >= count($options)) abort(422, 'An answer option is outside the allowed range.');
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
}
