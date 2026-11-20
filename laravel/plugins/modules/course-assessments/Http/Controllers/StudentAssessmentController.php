<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\CourseAssessments\Models\AssessmentAttempt;
use Plugins\Modules\CourseAssessments\Models\AssessmentSet;

class StudentAssessmentController extends Controller
{
    public function index(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $sets = $this->visibleSetsQuery($student)->latest()->paginate(20);

        return view('course-assessments::student-index', compact('sets'));
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $sets = $this->visibleSetsQuery($student)->latest()->paginate(20);

        return response()->json([
            'data' => $sets->getCollection()->map(fn (AssessmentSet $set): array => [
                'id' => $set->id,
                'course_id' => $set->course_id,
                'title' => $set->title,
                'description' => $set->description,
                'time_limit_minutes' => $set->time_limit_minutes,
                'max_attempts' => $set->max_attempts,
                'questions_per_attempt' => $set->questions_per_attempt,
                'questions_count' => $set->questions_count,
                'available_from' => $set->available_from?->toIso8601String(),
                'available_until' => $set->available_until?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $sets->currentPage(),
                'last_page' => $sets->lastPage(),
                'per_page' => $sets->perPage(),
                'total' => $sets->total(),
            ],
        ]);
    }

    public function start(Request $request, AssessmentSet $set): RedirectResponse
    {
        $attempt = $this->createOrResumeAttempt($request->user(), $set);

        return redirect()->route('plugins.course-assessments.student.take', $attempt);
    }

    public function apiStart(Request $request, AssessmentSet $set): JsonResponse
    {
        $attempt = $this->createOrResumeAttempt($request->user(), $set);
        $attempt->load('questions');

        return response()->json([
            'attempt' => $this->attemptPayload($attempt),
            'questions' => $attempt->questions->map(fn ($question): array => $this->attemptQuestionPayload($question))->values(),
        ], $attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function apiTake(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        abort_unless((int) $attempt->student_id === (int) $student->id, 404);

        if ($attempt->status === 'in_progress' && $attempt->deadline_at && now()->greaterThanOrEqualTo($attempt->deadline_at)) {
            $this->submitAttempt($attempt);
        }

        $attempt->refresh()->load('questions');

        return response()->json([
            'attempt' => $this->attemptPayload($attempt),
            'questions' => $attempt->questions->map(fn ($question): array => $this->attemptQuestionPayload($question))->values(),
        ]);
    }

    public function apiSubmit(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        abort_unless((int) $attempt->student_id === (int) $student->id, 404);

        $this->submitAttempt($attempt);
        $attempt->refresh();

        return response()->json([
            'message' => 'Assessment submitted.',
            'attempt' => $this->attemptPayload($attempt),
        ]);
    }

    public function apiHistory(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $attempts = AssessmentAttempt::query()
            ->with('assessmentSet')
            ->where('student_id', $student->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $attempts->getCollection()->map(function (AssessmentAttempt $attempt): array {
                return [
                    'id' => $attempt->id,
                    'assessment_set_id' => $attempt->assessment_set_id,
                    'title' => $attempt->assessmentSet?->title,
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $attempt->status,
                    'auto_score' => $attempt->auto_score,
                    'manual_score' => $attempt->manual_score,
                    'final_score' => $attempt->final_score,
                    'max_score' => $attempt->max_score,
                    'started_at' => $attempt->started_at?->toIso8601String(),
                    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                    'graded_at' => $attempt->graded_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    public function take(Request $request, AssessmentAttempt $attempt): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        abort_unless((int) $attempt->student_id === (int) $student->id, 404);
        abort_if($attempt->status!=='in_progress',403,'This attempt has already been submitted.');
        if($attempt->deadline_at && now()->greaterThanOrEqualTo($attempt->deadline_at)){
            $this->submitAttempt($attempt);
            return redirect()->route('plugins.course-assessments.student.history')->with('status','Time expired. Your answers were submitted.');
        }
        $attempt->load('questions');
        $questions=$attempt->questions->map(fn($q)=>[
            'id'=>$q->id,'type'=>$q->type,'question'=>$q->question,'options'=>$q->options,
            'marks'=>$q->marks,'selected_option'=>$q->selected_option,'answer_text'=>$q->answer_text,
        ]);
        return view('course-assessments::take',compact('attempt','questions'));
    }

    public function answer(Request $request, AssessmentAttempt $attempt, int $question): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student') && (int) $attempt->student_id === (int) $student->id, 404);
        $data = $request->validate([
            'selected_option' => ['nullable', 'integer', 'min:0', 'max:7'],
            'answer_text' => ['nullable', 'string', 'max:20000'],
        ]);

        DB::transaction(function () use ($attempt, $question, $student, $data): void {
            $lockedAttempt = AssessmentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedAttempt->student_id === (int) $student->id, 404);
            abort_unless($lockedAttempt->status === 'in_progress', 409, 'Attempt is not active.');
            abort_if($lockedAttempt->deadline_at && now()->greaterThanOrEqualTo($lockedAttempt->deadline_at), 409, 'Time has expired.');

            $item = $lockedAttempt->questions()->whereKey($question)->lockForUpdate()->firstOrFail();
            if (in_array($item->type, ['mcq', 'true_false'], true)) {
                abort_unless(
                    isset($data['selected_option']) && $data['selected_option'] < count($item->options ?? []),
                    422,
                    'Choose a valid option.'
                );
                $item->update(['selected_option' => $data['selected_option']]);
            } else {
                $item->update(['answer_text' => $data['answer_text'] ?? null]);
            }
        });

        return response()->json(['saved' => true, 'saved_at' => now()->toIso8601String()]);
    }

    public function submit(Request $request, AssessmentAttempt $attempt): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        abort_unless((int) $attempt->student_id === (int) $student->id, 404);
        $this->submitAttempt($attempt);
        return redirect()->route('plugins.course-assessments.student.history')->with('status','Assessment submitted.');
    }

    public function history(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);

        $attempts = AssessmentAttempt::query()->with('questions')->where('student_id', $student->id)->latest()->paginate(20);
        return view('course-assessments::history',compact('attempts'));
    }

    private function visibleSetsQuery($student)
    {
        $courseIntegration = app(PluginManager::class)->isEnabled('course-management')
            && Schema::hasTable('enrollments');
        $branchId = $this->assignedBranchId($student);

        return AssessmentSet::query()
            ->withCount('questions')
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>', now()))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where(function ($q) use ($student, $courseIntegration): void {
                $q->whereNull('course_id');
                if ($courseIntegration) {
                    $q->orWhereIn('course_id', function ($sub) use ($student): void {
                        $sub->select('course_id')
                            ->from('enrollments')
                            ->where('user_id', $student->id)
                            ->whereIn('status', ['active', 'completed']);
                    });
                }
            });
    }

    private function createOrResumeAttempt($student, AssessmentSet $set): AssessmentAttempt
    {
        abort_unless($student && $student->hasRole('student'), 403);

        return DB::transaction(function () use ($set, $student): AssessmentAttempt {
            $lockedSet = AssessmentSet::query()->whereKey($set->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSet->status === 'published', 404);
            abort_if($lockedSet->available_from && now()->lessThan($lockedSet->available_from), 403, 'Assessment is not available yet.');
            abort_if($lockedSet->available_until && now()->greaterThanOrEqualTo($lockedSet->available_until), 403, 'Assessment window has closed.');

            if ($lockedSet->branch_id !== null) {
                $branchId = $this->assignedBranchId($student);
                abort_unless($branchId !== null && $branchId === (int) $lockedSet->branch_id, 403, 'Assessment belongs to another branch.');
            }
            if ($lockedSet->course_id !== null) {
                abort_unless(
                    app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('enrollments'),
                    409,
                    'Course enrollment integration is unavailable.'
                );
                abort_unless(
                    DB::table('enrollments')->where('user_id', $student->id)->where('course_id', $lockedSet->course_id)
                        ->whereIn('status', ['active', 'completed'])->exists(),
                    403,
                    'An active course enrollment is required.'
                );
            }

            $existing = AssessmentAttempt::query()
                ->where('assessment_set_id', $lockedSet->id)
                ->where('student_id', $student->id)
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->first();
            if ($existing) return $existing;

            $count = AssessmentAttempt::query()
                ->where('assessment_set_id', $lockedSet->id)
                ->where('student_id', $student->id)
                ->count();
            abort_if($lockedSet->max_attempts !== null && $count >= $lockedSet->max_attempts, 403, 'Maximum attempts reached.');

            $questions = $lockedSet->questions()->get();
            abort_if($questions->isEmpty(), 422, 'This assessment has no questions.');
            if ($lockedSet->randomize_questions) $questions = $questions->shuffle();
            if ($lockedSet->questions_per_attempt) $questions = $questions->take($lockedSet->questions_per_attempt);

            $deadline = $lockedSet->time_limit_minutes ? now()->addMinutes($lockedSet->time_limit_minutes) : null;
            if ($lockedSet->available_until && (!$deadline || $lockedSet->available_until->lessThan($deadline))) {
                $deadline = $lockedSet->available_until;
            }

            $attempt = AssessmentAttempt::query()->create([
                'assessment_set_id' => $lockedSet->id,
                'course_id' => $lockedSet->course_id,
                'student_id' => $student->id,
                'attempt_number' => $count + 1,
                'status' => 'in_progress',
                'started_at' => now(),
                'deadline_at' => $deadline,
                'max_score' => $questions->sum(fn ($q) => (float) $q->marks),
            ]);

            foreach ($questions as $index => $question) {
                $attempt->questions()->create([
                    'source_question_id' => $question->source_question_id,
                    'type' => $question->type,
                    'question' => $question->question,
                    'options' => $question->options,
                    'correct_option' => $question->correct_option,
                    'correct_answer' => $question->correct_answer,
                    'explanation' => $question->explanation,
                    'marks' => $question->marks,
                    'position' => ($index + 1) * 10,
                ]);
            }

            return $attempt;
        });
    }

    private function attemptPayload(AssessmentAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'assessment_set_id' => $attempt->assessment_set_id,
            'course_id' => $attempt->course_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'deadline_at' => $attempt->deadline_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'auto_score' => $attempt->auto_score,
            'manual_score' => $attempt->manual_score,
            'final_score' => $attempt->final_score,
            'max_score' => $attempt->max_score,
            'graded_at' => $attempt->graded_at?->toIso8601String(),
        ];
    }

    private function attemptQuestionPayload($question): array
    {
        return [
            'id' => $question->id,
            'type' => $question->type,
            'question' => $question->question,
            'options' => $question->options,
            'marks' => $question->marks,
            'position' => $question->position,
            'selected_option' => $question->selected_option,
            'answer_text' => $question->answer_text,
        ];
    }

    private function submitAttempt(AssessmentAttempt $attempt): void
    {
        DB::transaction(function()use($attempt){
            $locked=AssessmentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='in_progress')return;
            $auto=0.0;$manual=0.0;$hasManual=false;
            foreach($locked->questions as $q){
                if(in_array($q->type,['mcq','true_false'],true)){
                    $correct=$q->selected_option!==null && (int)$q->selected_option===(int)$q->correct_option;
                    $q->update(['is_correct'=>$correct,'marks_awarded'=>$correct?$q->marks:0]);
                    if($correct)$auto+=(float)$q->marks;
                }else{$hasManual=true;}
            }
            $locked->update(['auto_score'=>$auto,'manual_score'=>$manual,'final_score'=>$hasManual?null:$auto,'status'=>$hasManual?'pending_review':'submitted','submitted_at'=>now()]);
        });
    }

    private function assignedBranchId(object $student): ?int
    {
        if (app(PluginManager::class)->isEnabled('student-management')
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


}
