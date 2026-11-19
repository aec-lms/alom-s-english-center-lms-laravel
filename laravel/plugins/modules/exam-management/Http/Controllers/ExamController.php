<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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
        $this->ensureAssignedBranch($actor);
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
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
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
            'max_attempts' => (int) ($validated['max_attempts'] ?? 1),
            'pass_marks' => (float) $validated['pass_marks'],
            'is_published' => $request->boolean('is_published', false),
        ]);

        $this->audit->log('exam.created', 'exam', (string) $exam->id, ['title' => $exam->title]);

        return back()->with('status', "Exam '{$exam->title}' created.");
    }

        public function update(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:360'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'pass_marks' => ['required', 'numeric', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $courseId = array_key_exists('course_id', $validated)
            ? ($validated['course_id'] !== null ? (int) $validated['course_id'] : null)
            : ($exam->course_id !== null ? (int) $exam->course_id : null);

        $updatedExam = DB::transaction(function () use ($exam, $actor, $validated, $courseId): Exam {
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $lockedExam->branch_id) {
                abort(403, 'Branch isolation violation.');
            }

            if ($courseId !== null) {
                abort_unless(app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management to attach an exam to a course.');
                $course = DB::table('courses')->where('id', $courseId)->first();
                abort_unless($course && (int) ($course->branch_id ?? 0) === (int) ($lockedExam->branch_id ?? 0), 422, 'Exam and course must belong to the same branch.');
            }

            $lockedExam->update([
                'course_id' => $courseId,
                'title' => $validated['title'],
                'duration_minutes' => (int) $validated['duration_minutes'],
                'max_attempts' => (int) ($validated['max_attempts'] ?? $lockedExam->max_attempts ?? 1),
                'pass_marks' => (float) $validated['pass_marks'],
                'is_published' => $request->boolean('is_published'),
            ]);

            return $lockedExam->fresh();
        });

        $this->audit->log('exam.updated', 'exam', (string) $updatedExam->id, ['title' => $updatedExam->title]);

        return back()->with('status', "Exam '{$updatedExam->title}' updated.");
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

        $this->audit->log('exam.deleted', 'exam', $id, ['title' => $title]);

        return back()->with('status', "Exam '{$title}' deleted.");
    }

    public function addQuestion(Request $request, Exam $exam): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['required', 'string', 'max:300'],
            'correct_option_index' => ['required', 'integer', 'min:0'],
            'marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
        ]);

        if ((int) $validated['correct_option_index'] >= count($validated['options'])) {
            return back()->withErrors(['correct_option_index' => 'Correct option index is out of bounds.']);
        }

        DB::transaction(function () use ($exam, $actor, $validated): void {
            // Use the same exam-row lock as attempt snapshot creation so a
            // question cannot slip into an attempt while its snapshot is read.
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $lockedExam->branch_id) {
                abort(403, 'Branch isolation violation.');
            }

            ExamQuestion::query()->create([
                'exam_id' => $lockedExam->id,
                'prompt' => $validated['prompt'],
                'options' => array_values($validated['options']),
                'correct_option_index' => (int) $validated['correct_option_index'],
                'marks' => (float) $validated['marks'],
            ]);
        });

        return back()->with('status', 'Question added to exam bank. New attempts will use the updated question set.');
    }

    public function take(Request $request, Exam $exam): View
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can take exams.');
        abort_unless($exam->is_published, 404);
        $this->assertStudentExamBranch($actor, $exam);
        if ($exam->starts_at && now()->lt($exam->starts_at)) {
            abort(403, 'This exam has not started yet.');
        }
        if ($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at)) {
            abort(403, 'The exam submission window has closed.');
        }
        if (Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(
                Schema::hasTable('learn_earn_exam_access')
                && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)
                    ->where('user_id', $actor->id)->where('status', 'granted')->exists(),
                403,
                'Payment is required before attempting this exam.'
            );
        }

        $attempt = $this->startOrResumeAttempt($exam, $actor);
        abort_if($attempt->submitted_at !== null, 403, 'Your exam time has expired or all attempts have been used.');
        $snapshot = $this->validatedAttemptSnapshot($attempt);
        $questions = collect($snapshot['questions'])->map(static fn (array $question): object => (object) [
            'id' => $question['id'],
            'prompt' => $question['prompt'],
            'options' => $question['options'],
            'marks' => $question['marks'],
        ]);
        $exam->setAttribute('duration_minutes', $snapshot['duration_minutes']);
        $exam->setAttribute('pass_marks', $snapshot['pass_marks']);
        $deadline = $attempt->started_at->copy()->addMinutes((int) $snapshot['duration_minutes']);

        return view('exam-management::take', compact('exam', 'questions', 'attempt', 'deadline'));
    }

    /**
     * Students can review only their own attempt summaries.
     * Correct answers and other learners' submissions are never exposed here.
     */
    public function history(Request $request, Exam $exam): View
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can view exam history.');
        $this->assertStudentExamBranch($actor, $exam);

        $attempts = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $actor->id)
            ->latest('id')
            ->paginate(10);

        return view('exam-management::history', compact('exam', 'attempts'));
    }

    /**
     * Persist partial answers without finalizing the attempt.
     * The server remains authoritative for ownership, allowed questions and timer.
     */
    public function saveAttemptAnswers(Request $request, Exam $exam): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can save exam answers.');
        abort_unless($exam->is_published, 404);
        $this->assertStudentExamBranch($actor, $exam);
        if ($exam->starts_at && now()->lt($exam->starts_at)) abort(403, 'This exam has not started yet.');
        if ($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at)) abort(403, 'The exam submission window has closed.');
        if (Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(Schema::hasTable('learn_earn_exam_access') && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)->where('user_id', $actor->id)->where('status', 'granted')->exists(), 403, 'Payment is required before saving this exam.');
        }

        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
            'answers' => ['required', 'array', 'max:500'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $savedAt = DB::transaction(function () use ($exam, $actor, $validated): string {
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $attempt = ExamAttempt::query()
                ->whereKey((int) $validated['attempt_id'])
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($attempt->submitted_at !== null, 409, 'This exam attempt is already submitted.');
            $snapshot = $this->validatedAttemptSnapshot($attempt);
            $deadline = $attempt->started_at?->copy()->addMinutes((int) $snapshot['duration_minutes']);
            if (!$deadline || now()->greaterThanOrEqualTo($deadline)) {
                abort(409, 'The exam time has expired.');
            }

            $questions = collect($snapshot['questions']);
            $allowedIds = $questions->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            $submittedIds = array_map('strval', array_keys($validated['answers']));
            abort_if(array_diff($submittedIds, $allowedIds) !== [], 422, 'An answer references a question outside this exam snapshot.');

            $answers = (array) ($attempt->submitted_answers ?? []);
            foreach ($questions as $question) {
                $key = (string) $question['id'];
                if (!array_key_exists($key, $validated['answers'])) {
                    continue;
                }
                $selected = $validated['answers'][$key];
                if ($selected !== null && (int) $selected >= count((array) $question['options'])) {
                    abort(422, 'An answer option is outside the allowed range.');
                }
                $answers[$key] = $selected !== null ? (int) $selected : null;
            }

            $attempt->update(['submitted_answers' => $answers]);

            return now()->toIso8601String();
        });

        return response()->json(['saved' => true, 'saved_at' => $savedAt]);
    }

    /**
     * Server-side authoritative grading, timer and attempt-limit enforcement.
     */
    public function submitAttempt(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->is_published, 403, 'Exam is not published for attempts.');
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can submit exam attempts.');
        if (Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(Schema::hasTable('learn_earn_exam_access') && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)->where('user_id', $actor->id)->where('status', 'granted')->exists(), 403, 'Payment is required before attempting this exam.');
        }
        $this->assertStudentExamBranch($actor, $exam);
        if ($exam->starts_at && now()->lt($exam->starts_at)) abort(403, 'This exam has not started yet.');
        if ($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at)) abort(403, 'The exam submission window has closed.');

        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = DB::transaction(function () use ($exam, $actor, $validated): array {
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $attempt = ExamAttempt::query()
                ->whereKey((int) $validated['attempt_id'])
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($attempt->submitted_at !== null, 409, 'This exam attempt has already been submitted.');

            $snapshot = $this->validatedAttemptSnapshot($attempt);
            $questions = collect($snapshot['questions']);
            $deadline = $attempt->started_at?->copy()->addMinutes((int) $snapshot['duration_minutes']);
            $total = (float) $questions->sum('marks');

            if (!$deadline || now()->greaterThanOrEqualTo($deadline)) {
                $attempt->update([
                    'submitted_answers' => [],
                    'score_obtained' => 0,
                    'total_marks' => $total,
                    'passed' => false,
                    'submitted_at' => now(),
                ]);

                return ['expired' => true, 'attempt' => $attempt->fresh(), 'score' => 0.0, 'total' => $total];
            }

            $allowedIds = $questions->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            $submittedIds = array_map('strval', array_keys($validated['answers']));
            abort_if(array_diff($submittedIds, $allowedIds) !== [], 422, 'An answer references a question outside this exam snapshot.');

            $score = 0.0;
            $sanitizedAnswers = [];
            foreach ($questions as $question) {
                $selected = $validated['answers'][(string) $question['id']] ?? null;
                if ($selected !== null && (int) $selected >= count((array) $question['options'])) {
                    abort(422, 'An answer option is outside the allowed range.');
                }
                if ($selected !== null && (int) $selected === (int) $question['correct_option_index']) {
                    $score += (float) $question['marks'];
                } elseif ($selected !== null) {
                    $score -= (float) ($question['negative_marks'] ?? 0);
                }
                $sanitizedAnswers[(string) $question['id']] = $selected !== null ? (int) $selected : null;
            }

            $attempt->update([
                'submitted_answers' => $sanitizedAnswers,
                'score_obtained' => $score,
                'total_marks' => $total,
                'passed' => $score >= (float) $snapshot['pass_marks'],
                'submitted_at' => now(),
            ]);

            return ['expired' => false, 'attempt' => $attempt->fresh(), 'score' => $score, 'total' => $total];
        });

        if ($result['expired']) {
            $this->audit->log('exam.attempt_expired', 'exam_attempt', (string) $result['attempt']->id, ['exam_id' => $exam->id], $actor->id);
            return redirect()->route('portal.dashboard')->withErrors(['exam' => 'Your exam time expired. The attempt was submitted with zero marks.']);
        }

        $this->audit->log('exam.attempted', 'exam_attempt', (string) $result['attempt']->id, [
            'exam_id' => $exam->id,
            'score' => $result['score'],
            'total' => $result['total'],
        ], $actor->id);

        return redirect()->route('portal.dashboard')->with('status', "Exam graded server-side: {$result['score']}/{$result['total']} marks.");
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Student accounts only.');

        $branchId = $this->assignedBranchId($student);
        $exams = Exam::query()
            ->where('is_published', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => $exams->getCollection()->map(fn (Exam $exam): array => [
                'id' => $exam->id,
                'title' => $exam->title,
                'course_id' => $exam->course_id,
                'duration_minutes' => $exam->duration_minutes,
                'max_attempts' => $exam->max_attempts,
                'pass_marks' => $exam->pass_marks,
                'starts_at' => $exam->starts_at?->toIso8601String(),
                'ends_at' => $exam->ends_at?->toIso8601String(),
                'learn_earn_type' => $exam->learn_earn_type,
                'access_price' => $exam->access_price,
            ]),
            'meta' => [
                'current_page' => $exams->currentPage(),
                'last_page' => $exams->lastPage(),
                'per_page' => $exams->perPage(),
                'total' => $exams->total(),
            ],
        ]);
    }

    public function apiTake(Request $request, Exam $exam): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can take exams.');
        abort_unless($exam->is_published, 404);
        $this->assertStudentExamBranch($actor, $exam);
        abort_if($exam->starts_at && now()->lt($exam->starts_at), 403, 'This exam has not started yet.');
        abort_if($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at), 403, 'The exam submission window has closed.');

        if (Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(
                Schema::hasTable('learn_earn_exam_access')
                && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)
                    ->where('user_id', $actor->id)->where('status', 'granted')->exists(),
                403,
                'Payment is required before attempting this exam.'
            );
        }

        $attempt = $this->startOrResumeAttempt($exam, $actor);
        abort_if($attempt->submitted_at !== null, 403, 'Your exam time has expired or all attempts have been used.');
        $snapshot = $this->validatedAttemptSnapshot($attempt);
        $deadline = $attempt->started_at->copy()->addMinutes((int) $snapshot['duration_minutes']);

        return response()->json([
            'attempt' => [
                'id' => $attempt->id,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'deadline' => $deadline->toIso8601String(),
            ],
            // Correct answers are retained only in the private attempt snapshot.
            'questions' => collect($snapshot['questions'])->map(static fn (array $question): array => [
                'id' => $question['id'],
                'prompt' => $question['prompt'],
                'options' => $question['options'],
                'marks' => $question['marks'],
            ])->values(),
        ]);
    }

    public function apiSubmitAttempt(Request $request, Exam $exam, int $attempt): JsonResponse
    {
        abort_unless($exam->is_published, 403, 'Exam is not published for attempts.');
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only student accounts can submit exam attempts.');
        $this->assertStudentExamBranch($actor, $exam);
        abort_if($exam->starts_at && now()->lt($exam->starts_at), 403, 'This exam has not started yet.');
        abort_if($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at), 403, 'The exam submission window has closed.');

        if (Schema::hasColumn('exams', 'learn_earn_type') && $exam->learn_earn_type === 'paid') {
            abort_unless(
                Schema::hasTable('learn_earn_exam_access')
                && DB::table('learn_earn_exam_access')->where('exam_id', $exam->id)
                    ->where('user_id', $actor->id)->where('status', 'granted')->exists(),
                403,
                'Payment is required before attempting this exam.'
            );
        }

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = DB::transaction(function () use ($exam, $actor, $attempt, $validated): array {
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $record = ExamAttempt::query()
                ->whereKey($attempt)
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($record->submitted_at !== null, 409, 'This exam attempt has already been submitted.');

            $snapshot = $this->validatedAttemptSnapshot($record);
            $questions = collect($snapshot['questions']);
            $deadline = $record->started_at?->copy()->addMinutes((int) $snapshot['duration_minutes']);
            $total = (float) $questions->sum('marks');

            if (!$deadline || now()->greaterThanOrEqualTo($deadline)) {
                $record->update([
                    'submitted_answers' => [],
                    'score_obtained' => 0,
                    'total_marks' => $total,
                    'passed' => false,
                    'submitted_at' => now(),
                ]);

                return ['expired' => true, 'attempt' => $record->fresh(), 'score' => 0.0, 'total' => $total];
            }

            $allowedIds = $questions->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            $submittedIds = array_map('strval', array_keys($validated['answers']));
            abort_if(array_diff($submittedIds, $allowedIds) !== [], 422, 'An answer references a question outside this exam snapshot.');

            $score = 0.0;
            $sanitizedAnswers = [];
            foreach ($questions as $question) {
                $selected = $validated['answers'][(string) $question['id']] ?? null;
                if ($selected !== null && (int) $selected >= count((array) $question['options'])) {
                    abort(422, 'An answer option is outside the allowed range.');
                }
                if ($selected !== null && (int) $selected === (int) $question['correct_option_index']) {
                    $score += (float) $question['marks'];
                } elseif ($selected !== null) {
                    $score -= (float) ($question['negative_marks'] ?? 0);
                }
                $sanitizedAnswers[(string) $question['id']] = $selected !== null ? (int) $selected : null;
            }

            $record->update([
                'submitted_answers' => $sanitizedAnswers,
                'score_obtained' => $score,
                'total_marks' => $total,
                'passed' => $score >= (float) $snapshot['pass_marks'],
                'submitted_at' => now(),
            ]);

            return ['expired' => false, 'attempt' => $record->fresh(), 'score' => $score, 'total' => $total];
        });

        if ($result['expired']) {
            $this->audit->log('exam.attempt_expired', 'exam_attempt', (string) $result['attempt']->id, ['exam_id' => $exam->id], $actor->id);
            return response()->json([
                'message' => 'Your exam time expired. The attempt was submitted with zero marks.',
                'data' => ['attempt_id' => $result['attempt']->id, 'score' => 0, 'total_marks' => $result['total'], 'passed' => false],
            ], 200);
        }

        $this->audit->log('exam.attempted', 'exam_attempt', (string) $result['attempt']->id, [
            'exam_id' => $exam->id,
            'score' => $result['score'],
            'total' => $result['total'],
        ], $actor->id);

        return response()->json([
            'message' => 'Exam submitted and graded.',
            'data' => [
                'attempt_id' => $result['attempt']->id,
                'score' => $result['score'],
                'total_marks' => $result['total'],
                'passed' => $result['attempt']->passed,
                'submitted_at' => $result['attempt']->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    private function startOrResumeAttempt(Exam $exam, object $actor): ExamAttempt
    {
        return DB::transaction(function () use ($exam, $actor): ExamAttempt {
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $pending = ExamAttempt::query()
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $actor->id)
                ->whereNull('submitted_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $expiredPending = false;
            if ($pending) {
                // A legacy in-progress attempt has no provable start-time question set.
                // Never silently grade it against today's mutable exam content.
                $snapshot = $this->validatedAttemptSnapshot($pending);
                $deadline = $pending->started_at?->copy()->addMinutes((int) $snapshot['duration_minutes']);
                if (!$deadline || now()->greaterThanOrEqualTo($deadline)) {
                    $total = (float) collect($snapshot['questions'])->sum('marks');
                    $pending->update([
                        'submitted_answers' => [],
                        'score_obtained' => 0,
                        'total_marks' => $total,
                        'passed' => false,
                        'submitted_at' => now(),
                    ]);
                    $expiredPending = true;
                } else {
                    return $pending;
                }
            }

            $completedAttempts = ExamAttempt::query()
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $actor->id)
                ->whereNotNull('submitted_at')
                ->count();
            if ($completedAttempts >= max(1, (int) $lockedExam->max_attempts)) {
                if ($expiredPending) {
                    return $pending->fresh();
                }
                abort(403, 'You have used all allowed attempts for this exam.');
            }

            $snapshot = $this->buildExamSnapshot($lockedExam);
            return ExamAttempt::query()->create([
                'exam_id' => $lockedExam->id,
                'user_id' => $actor->id,
                'started_at' => now(),
                'submitted_answers' => null,
                'exam_snapshot' => $snapshot,
                'score_obtained' => 0,
                'total_marks' => 0,
                'passed' => false,
                'submitted_at' => null,
            ]);
        });
    }

    /**
     * Capture every mutable input that affects delivery, timing or grading.
     * The exam row is locked by the caller before question rows are read.
     *
     * @return array<string, mixed>
     */
    private function buildExamSnapshot(Exam $exam): array
    {
        $questions = $exam->questions()->orderBy('id')->lockForUpdate()->get();
        abort_if($questions->isEmpty(), 404, 'This exam has no questions yet.');

        return [
            'version' => 1,
            'duration_minutes' => (int) $exam->duration_minutes,
            'pass_marks' => (float) $exam->pass_marks,
            'questions' => $questions->map(static fn (ExamQuestion $question): array => [
                'id' => (int) $question->id,
                'prompt' => (string) $question->prompt,
                'options' => array_values((array) $question->options),
                'correct_option_index' => (int) $question->correct_option_index,
                'marks' => (float) $question->marks,
                'negative_marks' => (float) ($question->negative_marks ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * Historical attempts remain untouched. Legacy pending attempts without a
     * start-time snapshot are blocked rather than being silently regraded.
     *
     * @return array<string, mixed>
     */
    private function validatedAttemptSnapshot(ExamAttempt $attempt): array
    {
        $snapshot = $attempt->exam_snapshot;
        abort_unless(
            is_array($snapshot)
                && (int) ($snapshot['version'] ?? 0) === 1
                && (int) ($snapshot['duration_minutes'] ?? 0) > 0
                && is_array($snapshot['questions'] ?? null)
                && count($snapshot['questions']) > 0,
            409,
            'This legacy exam attempt has no immutable question snapshot and cannot be safely resumed or graded. Please contact an administrator.'
        );

        return $snapshot;
    }

    private function assertStudentExamBranch(object $student, Exam $exam): void
    {
        if ($exam->branch_id !== null) {
            $branchId = $this->assignedBranchId($student);
            abort_unless($branchId !== null && $branchId === (int) $exam->branch_id, 403, 'Exam is outside your assigned branch.');
        }
    }

    private function assignedBranchId(object $student): ?int
    {
        if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $student->id)->value('branch_id');
            if ($profileBranchId !== null) {
                return (int) $profileBranchId;
            }
        }

        return Schema::hasColumn('users', 'branch_id') && $student->branch_id !== null
            ? (int) $student->branch_id
            : null;
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        abort_unless(
            (int) ($actor->branch_id ?? 0) > 0
                && app(\App\Core\Plugins\PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

}
