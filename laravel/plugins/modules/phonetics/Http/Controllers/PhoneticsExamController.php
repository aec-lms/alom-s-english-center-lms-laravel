<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Plugins\Modules\Phonetics\Models\PhoneticExam;
use Plugins\Modules\Phonetics\Models\PhoneticExamAttempt;
use Plugins\Modules\Phonetics\Models\PhoneticExamQuestion;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;
use Plugins\Modules\Phonetics\Services\CourseCatalog;

final class PhoneticsExamController extends Controller
{
    public function __construct(private readonly CourseCatalog $catalog) {}

    public function start(Request $request, string $courseSlug, string $examSlug): View
    {
        abort_unless($request->user(), 401);
        $setting = PhoneticSetting::current();
        abort_unless($setting->enabled, 404);
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);\n        $this->catalog->assertDedicatedCourse($course);
        $courseRef = $this->catalog->courseId($course);
        $this->catalog->assertAccess($request->user(), $courseRef);

        $exam = $this->resolveExam($examSlug, $course, $request->query('lesson'));
        if ($exam->batch_ref) {
            abort_unless($this->catalog->isBatchMember($request->user(), (string) $exam->batch_ref), 403, 'This exam is restricted to another batch.');
        }
        $this->assertAudience($exam, $course);

        $questions = $exam->questions()->get();
        abort_if($questions->isEmpty(), 404, 'This exam has no questions yet.');
        if ($exam->shuffle_questions) {
            $questions = $questions->shuffle()->values();
        }
        $snapshot = $questions->map(function (PhoneticExamQuestion $question) use ($exam): array {
            $options = $question->options ?? [];
            if ($exam->shuffle_options) {
                shuffle($options);
            }
            return [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'options' => $options,
                'marks' => (int) $question->marks,
            ];
        })->all();

        $attempt = DB::transaction(function () use ($exam, $request, $snapshot, $questions): PhoneticExamAttempt {
            $lockedExam = PhoneticExam::query()->lockForUpdate()->findOrFail($exam->id);
            abort_unless($lockedExam->status === 'published', 404, 'This examination is no longer available.');
            abort_unless(
                (!$lockedExam->available_from || $lockedExam->available_from->lte(now()))
                && (!$lockedExam->available_until || $lockedExam->available_until->gte(now())),
                404,
                'This examination is outside its availability window.'
            );

            if ($lockedExam->max_attempts > 0) {
                $count = PhoneticExamAttempt::query()
                    ->where('exam_id', $lockedExam->id)
                    ->where('user_id', (string) $request->user()->getAuthIdentifier())
                    ->where(function ($attempts): void {
                        $attempts->where('status', 'submitted')
                            ->orWhere(fn ($active) => $active->where('status', 'in_progress')
                                ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now())));
                    })->count();
                abort_if($count >= $lockedExam->max_attempts, 403, 'You have used all attempts for this examination.');
            }

            return PhoneticExamAttempt::query()->create([
                'exam_id' => $lockedExam->id,
                'user_id' => (string) $request->user()->getAuthIdentifier(),
                'status' => 'in_progress',
                'question_snapshot' => $snapshot,
                'answers' => [],
                'total_marks' => $questions->sum('marks'),
                'started_at' => now(),
                'expires_at' => $lockedExam->duration_minutes > 0 ? now()->addMinutes($lockedExam->duration_minutes) : null,
            ]);
        });

        return view('phonetics::learning.exam', [
            'course' => $course,
            'exam' => $exam,
            'attempt' => $attempt,
            'questions' => $snapshot,
            'result' => null,
            'lessonRef' => $request->query('lesson'),
        ]);
    }

    public function submit(Request $request, string $courseSlug, string $examSlug, PhoneticExamAttempt $attempt): View
    {
        abort_unless($request->user(), 401);
        $setting = PhoneticSetting::current();
        abort_unless($setting->enabled, 404);
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $courseRef = $this->catalog->courseId($course);
        if ($setting->require_course_enrollment) {
            $this->catalog->assertAccess($request->user(), $courseRef);
        }
        $exam = $this->resolveExam($examSlug, $course, $request->query('lesson'));
        $this->assertAudience($exam, $course);
        if ($exam->batch_ref) {
            abort_unless($this->catalog->isBatchMember($request->user(), (string) $exam->batch_ref), 403, 'This exam is restricted to another batch.');
        }
        abort_unless($attempt->exam_id === $exam->id && (string) $attempt->user_id === (string) $request->user()->getAuthIdentifier(), 404);
        abort_unless($attempt->status === 'in_progress', 409, 'This attempt has already been submitted.');
        abort_if($attempt->expires_at && $attempt->expires_at->isPast(), 410, 'The examination time has expired.');

        $data = $request->validate(['answers' => ['nullable', 'array'], 'answers.*' => ['nullable', 'string', 'max:255']]);
        $snapshot = $attempt->question_snapshot ?? [];
        $questionIds = collect($snapshot)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $questions = PhoneticExamQuestion::query()->where('exam_id', $exam->id)->whereIn('id', $questionIds)->get()->keyBy('id');
        $answers = $data['answers'] ?? [];
        $score = 0;
        $total = 0;
        foreach ($snapshot as $item) {
            $question = $questions->get((int) $item['id']);
            if (!$question) {
                continue;
            }
            $marks = (int) $question->marks;
            $total += $marks;
            $selected = (string) ($answers[(string) $question->id] ?? '');
            if ($selected !== '' && hash_equals((string) $question->answer_key, $selected)) {
                $score += $marks;
            }
        }
        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;
        $passed = $percentage >= (int) $exam->pass_percentage;

        DB::transaction(function () use ($attempt, $answers, $score, $total, $percentage, $passed): void {
            $locked = PhoneticExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($locked->status === 'in_progress', 409, 'This attempt has already been submitted.');
            abort_if($locked->expires_at && $locked->expires_at->isPast(), 410, 'The examination time has expired.');
            $locked->update([
                'answers' => $answers,
                'score' => $score,
                'total_marks' => $total,
                'percentage' => $percentage,
                'passed' => $passed,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
        });

        $attempt->refresh();
        return view('phonetics::learning.exam', [
            'course' => $course,
            'exam' => $exam,
            'attempt' => $attempt,
            'questions' => $snapshot,
            'lessonRef' => $request->query('lesson'),
            'result' => $exam->show_result_immediately ? [
                'score' => $score,
                'total' => $total,
                'percentage' => $percentage,
                'passed' => $passed,
            ] : ['hidden' => true],
        ]);
    }

    private function resolveExam(string $examSlug, object $course, ?string $lessonRef): PhoneticExam
    {
        $courseRef = $this->catalog->courseId($course);
        $categoryRef = $this->catalog->courseCategoryId($course);
        $exam = PhoneticExam::query()
            ->where('slug', $examSlug)
            ->where('lms_driver', $this->catalog->driver())
            ->where('status', 'published')
            ->where(fn ($scope) => $scope->whereNull('course_ref')->orWhere('course_ref', $courseRef))
            ->where(fn ($scope) => $scope->whereNull('course_category_ref')->orWhere('course_category_ref', $categoryRef ?? '__none__'))
            ->where(fn ($scope) => $scope->whereNull('lesson_ref')->orWhere('lesson_ref', $lessonRef ?? '__course_overview__'))
            ->where(fn ($dates) => $dates->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($dates) => $dates->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->firstOrFail();
        return $exam;
    }

    private function assertAudience(PhoneticExam $exam, object $course): void
    {
        $isPaid = $this->catalog->isPaid($course);
        abort_unless(
            $exam->audience === 'both' || ($exam->audience === 'paid' && $isPaid) || ($exam->audience === 'free' && !$isPaid),
            403,
            'This exam is not available for this course type.'
        );
    }
}
