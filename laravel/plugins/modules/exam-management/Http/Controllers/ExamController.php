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
        $exams = Exam::query()
            ->withCount(['questions', 'attempts'])
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(20);
        $courses = Schema::hasTable('courses') ? DB::table('courses')->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))->orderBy('title')->get() : collect();

        return view('exam-management::index', compact('exams', 'courses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'integer', 'min:1'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $course = DB::table('courses')->where('id', (int) $validated['course_id'])->first();
        if (!$course) abort(422, 'Selected course does not exist.');
        if ($actor && $actor->hasRole('branch_manager') && (int) $course->branch_id !== (int) $actor->branch_id) {
            abort(403, 'Course is outside your assigned branch.');
        }
        $resolvedBranchId = $actor && $actor->hasRole('branch_manager')
            ? (int) $actor->branch_id
            : (isset($validated['branch_id']) ? (int) $validated['branch_id'] : ($course->branch_id !== null ? (int) $course->branch_id : null));
        if ((int) ($course->branch_id ?? 0) !== (int) ($resolvedBranchId ?? 0)) {
            abort(422, 'Exam and course must belong to the same branch.');
        }
        $validated['branch_id'] = $resolvedBranchId;

        $exam = Exam::query()->create([
            'course_id' => (int) $validated['course_id'],
            'branch_id' => $validated['branch_id'] ?? null,
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
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'pass_marks' => ['required', 'integer', 'min:1'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $courseId = (int) ($validated['course_id'] ?? $exam->course_id);
        $course = DB::table('courses')->where('id', $courseId)->first();
        if (!$course || (int) $course->branch_id !== (int) $exam->branch_id) {
            abort(422, 'Exam and course must belong to the same branch.');
        }

        $exam->update([
            'course_id' => $courseId,
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
        if ($actor && $actor->hasRole('student') && (int) $actor->branch_id !== (int) $exam->branch_id) {
            abort(403, 'Exam is outside your assigned branch.');
        }

        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $questions = $exam->questions()->get();
        $score = 0;
        $total = 0;
        $sanitizedAnswers = [];

        foreach ($questions as $question) {
            $total += (int) $question->marks;
            $selected = $validated['answers'][(string) $question->id] ?? null;
            if ($selected !== null && (int) $selected === (int) $question->correct_option_index) {
                $score += (int) $question->marks;
            }
            $sanitizedAnswers[(string) $question->id] = $selected !== null ? (int) $selected : null;
        }

        $attempt = ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'user_id' => (int) $request->user()->id,
            'submitted_answers' => $sanitizedAnswers,
            'score_obtained' => $score,
            'total_marks' => $total,
            'passed' => $score >= (int) $exam->pass_marks,
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
