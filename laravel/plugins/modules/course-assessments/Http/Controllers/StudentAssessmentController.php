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

        $courseIntegration = app(PluginManager::class)->isEnabled('course-management')
            && Schema::hasTable('enrollments');

        $sets = AssessmentSet::query()
            ->withCount('questions')
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
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
            })
            ->latest()
            ->paginate(20);

        return view('course-assessments::student-index', compact('sets'));
    }

    public function start(Request $request, AssessmentSet $set): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);

        $attempt = DB::transaction(function () use ($set, $student) {
            $lockedSet = AssessmentSet::query()->whereKey($set->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSet->status === 'published', 404);
            abort_if($lockedSet->available_from && now()->lt($lockedSet->available_from), 403, 'Assessment is not available yet.');
            abort_if($lockedSet->available_until && now()->gt($lockedSet->available_until), 403, 'Assessment window has closed.');

            if ($lockedSet->branch_id !== null) {
                abort_unless((int) $student->branch_id === (int) $lockedSet->branch_id, 403, 'Assessment belongs to another branch.');
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
            if ($existing) {
                return $existing;
            }

            $count = AssessmentAttempt::query()
                ->where('assessment_set_id', $lockedSet->id)
                ->where('student_id', $student->id)
                ->count();
            abort_if($lockedSet->max_attempts !== null && $count >= $lockedSet->max_attempts, 403, 'Maximum attempts reached.');

            $questions = $lockedSet->questions()->get();
            abort_if($questions->isEmpty(), 422, 'This assessment has no questions.');
            if ($lockedSet->randomize_questions) $questions = $questions->shuffle();
            if ($lockedSet->questions_per_attempt) $questions = $questions->take($lockedSet->questions_per_attempt);

            $attempt = AssessmentAttempt::query()->create([
                'assessment_set_id' => $lockedSet->id,
                'course_id' => $lockedSet->course_id,
                'student_id' => $student->id,
                'attempt_number' => $count + 1,
                'status' => 'in_progress',
                'started_at' => now(),
                'deadline_at' => $lockedSet->time_limit_minutes ? now()->addMinutes($lockedSet->time_limit_minutes) : null,
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

        return redirect()->route('plugins.course-assessments.student.take', $attempt);
    }

    public function take(Request $request,AssessmentAttempt $attempt): View
    {
        $student=$request->user();
        abort_unless($attempt->student_id===$student->id,404);
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

    public function submit(Request $request,AssessmentAttempt $attempt): RedirectResponse
    {
        abort_unless($attempt->student_id===$request->user()->id,404);
        $this->submitAttempt($attempt);
        return redirect()->route('plugins.course-assessments.student.history')->with('status','Assessment submitted.');
    }

    public function history(Request $request): View
    {
        $attempts=AssessmentAttempt::query()->with('questions')->where('student_id',$request->user()->id)->latest()->paginate(20);
        return view('course-assessments::history',compact('attempts'));
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
}
