<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\Phonetics\Models\PhoneticAttempt;
use Plugins\Modules\Phonetics\Models\PhoneticEntry;
use Plugins\Modules\Phonetics\Models\PhoneticLearningAssignment;
use Plugins\Modules\Phonetics\Models\PhoneticExam;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;
use Plugins\Modules\Phonetics\Services\CourseCatalog;

final class PhoneticsLearningController extends Controller
{
    public function __construct(private readonly CourseCatalog $catalog) {}

    public function index(Request $request, string $courseSlug): View
    {
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $courseRef = $this->catalog->courseId($course);
        $setting = PhoneticSetting::current();
        abort_unless($setting->enabled, 404);
        abort_unless($request->user(), 401, 'Sign in to access course vocabulary.');
        $this->catalog->assertAccess($request->user(), $courseRef);

        $entries = $this->assignedEntries($request, $course, $request->query('lesson'));
        $isPaid = $this->catalog->isPaid($course);
        $exams = PhoneticExam::query()->where('lms_driver', $this->catalog->driver())->where('status', 'published')
            ->where(fn ($scope) => $scope->whereNull('course_ref')->orWhere('course_ref', $courseRef))
            ->where(fn ($scope) => $scope->whereNull('course_category_ref')->orWhere('course_category_ref', $this->catalog->courseCategoryId($course) ?? '__none__'))
            ->where(fn ($scope) => $scope->whereNull('lesson_ref')->orWhere('lesson_ref', $request->query('lesson') ?? '__course_overview__'))
            ->whereIn('audience', ['both', $isPaid ? 'paid' : 'free'])
            ->where(fn ($dates) => $dates->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($dates) => $dates->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->get()
            ->filter(fn ($exam) => !$exam->batch_ref || ($request->user() && $this->catalog->isBatchMember($request->user(), (string) $exam->batch_ref)))
            ->values();
        $challenge = null;
        if ($entries->isNotEmpty()) {
            $entry = $entries->random();
            $accent = random_int(0, 1) === 0 ? 'uk' : 'us';
            $challenge = [
                'entry_id' => $entry->id,
                'accent' => $accent,
                'expected' => (string) $entry->{$accent.'_ipa'},
                'word' => $entry->word,
                'prompt' => 'Choose the '.($accent === 'uk' ? 'British' : 'American').' pronunciation of “'.$entry->word.'”.',
                'choices' => [
                    ['value' => (string) $entry->uk_ipa, 'label' => 'British English — '.$entry->uk_ipa],
                    ['value' => (string) $entry->us_ipa, 'label' => 'American English — '.$entry->us_ipa],
                ],
                'expires_at' => now()->addMinutes(15)->timestamp,
                'course_ref' => $courseRef,
                'lesson_ref' => $request->query('lesson'),
            ];
            $request->session()->put('phonetics.learning_challenge', $challenge);
        } else {
            $request->session()->forget('phonetics.learning_challenge');
        }

        return view('phonetics::learning.practice', [
            'course' => $course,
            'setting' => $setting,
            'entries' => $entries,
            'exams' => $exams,
            'challenge' => $challenge,
            'lessonRef' => $request->query('lesson'),
        ]);
    }

    public function attempt(Request $request, string $courseSlug): JsonResponse
    {
        $data = $request->validate(['selected_answer' => ['required', 'string', 'max:120']]);
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $courseRef = $this->catalog->courseId($course);
        $setting = PhoneticSetting::current();
        abort_unless($setting->enabled, 404);
        abort_unless($request->user(), 401, 'Sign in to access course vocabulary.');
        $this->catalog->assertAccess($request->user(), $courseRef);
        abort_unless($request->user(), 401, 'Sign in to save your practice result.');

        $challenge = $request->session()->get('phonetics.learning_challenge');
        abort_unless(
            is_array($challenge)
            && (string) ($challenge['course_ref'] ?? '') === $courseRef
            && (int) ($challenge['expires_at'] ?? 0) >= now()->timestamp,
            409,
            'Practice challenge expired. Reload the page.'
        );
        $request->session()->forget('phonetics.learning_challenge');

        $entries = $this->assignedEntries($request, $course, $challenge['lesson_ref'] ?? null);
        $entry = $entries->firstWhere('id', (int) $challenge['entry_id']);
        abort_unless($entry, 403, 'This word is no longer assigned to your course or batch.');

        $correct = hash_equals((string) $challenge['expected'], trim($data['selected_answer']));
        $attempt = PhoneticAttempt::query()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'entry_id' => $entry->id,
            'target_accent' => $challenge['accent'],
            'quiz_mode' => 'course-vocabulary',
            'selected_answer' => trim($data['selected_answer']),
            'is_correct' => $correct,
        ]);

        return response()->json([
            'saved' => true,
            'correct' => $correct,
            'attempt_id' => $attempt->id,
            'message' => $correct ? 'Correct answer.' : 'Not quite. Review the word card and try again.',
        ]);
    }

    private function assignedEntries(Request $request, object $course, ?string $lessonRef)
    {
        $courseRef = $this->catalog->courseId($course);
        $categoryRef = $this->catalog->courseCategoryId($course);
        $isPaid = $this->catalog->isPaid($course);
        $query = PhoneticLearningAssignment::query()
            ->with('word')
            ->where('lms_driver', $this->catalog->driver())
            ->where(fn ($scope) => $scope->whereNull('course_ref')->orWhere('course_ref', $courseRef))
            ->where(fn ($scope) => $scope->whereNull('course_category_ref')->orWhere('course_category_ref', $categoryRef ?? '__none__'))
            ->where(fn ($scope) => $scope->whereNull('lesson_ref')->orWhere('lesson_ref', $lessonRef ?? '__course_overview__'))
            ->whereIn('audience', ['both', $isPaid ? 'paid' : 'free'])
            ->where(fn ($dates) => $dates->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($dates) => $dates->whereNull('available_until')->orWhere('available_until', '>=', now()));

        $assignments = $query->orderBy('sort_order')->get();
        $words = $assignments->filter(function ($assignment) use ($request): bool {
            if ($assignment->batch_ref === null) {
                return true;
            }
            if (!$request->user()) {
                return false;
            }
            return $this->catalog->isBatchMember($request->user(), (string) $assignment->batch_ref);
        })->map(fn ($assignment) => $assignment->word)
          ->filter(fn ($word) => $word && $word->is_active)
          ->unique('id')
          ->values();

        return $words;
    }
}
