<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
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
        $progress = [
            'attempts' => 0,
            'correct' => 0,
            'accuracy' => 0,
            'words_practiced' => 0,
            'uk_attempts' => 0,
            'us_attempts' => 0,
            'recent_7_days' => 0,
            'recent_30_days' => 0,
            'speaking_reviewed' => 0,
            'speaking_pending' => 0,
            'speaking_average_score' => null,
        ];
        $dailyActivity = collect(range(6, 0))->map(fn (int $daysAgo): array => [
            'date' => now()->startOfDay()->subDays($daysAgo)->toDateString(),
            'label' => now()->startOfDay()->subDays($daysAgo)->format('D'),
            'attempts' => 0,
            'bar_percent' => 0,
        ]);
        $weakWords = collect();
        $reviewQueue = collect();
        $accentAccuracy = ['uk' => null, 'us' => null];

        if ($entries->isNotEmpty()) {
            $progressQuery = PhoneticAttempt::query()
                ->where('user_id', (string) $request->user()->getAuthIdentifier())
                ->where('course_ref', $courseRef)
                ->whereIn('entry_id', $entries->pluck('id'));
            $progress['attempts'] = (clone $progressQuery)->count();
            $progress['correct'] = (clone $progressQuery)->where('is_correct', true)->count();
            $progress['accuracy'] = $progress['attempts'] > 0
                ? (int) round(($progress['correct'] / $progress['attempts']) * 100)
                : 0;
            $progress['words_practiced'] = (clone $progressQuery)->distinct()->count('entry_id');
            $progress['uk_attempts'] = (clone $progressQuery)->where('target_accent', 'uk')->count();
            $progress['us_attempts'] = (clone $progressQuery)->where('target_accent', 'us')->count();

            $sevenDayStart = now()->startOfDay()->subDays(6);
            $progress['recent_7_days'] = (clone $progressQuery)->where('created_at', '>=', $sevenDayStart)->count();
            $progress['recent_30_days'] = (clone $progressQuery)->where('created_at', '>=', now()->subDays(30))->count();

            $dailyRows = (clone $progressQuery)
                ->where('created_at', '>=', $sevenDayStart)
                ->selectRaw('DATE(created_at) as practice_date, COUNT(*) as attempts')
                ->groupBy('practice_date')
                ->get()
                ->keyBy('practice_date');
            $dailyActivity = $dailyActivity->map(function (array $day) use ($dailyRows): array {
                $day['attempts'] = (int) ($dailyRows->get($day['date'])->attempts ?? 0);
                return $day;
            });
            $maxDailyAttempts = max(1, (int) $dailyActivity->max('attempts'));
            $dailyActivity = $dailyActivity->map(function (array $day) use ($maxDailyAttempts): array {
                $day['bar_percent'] = $day['attempts'] > 0
                    ? max(8, (int) round(($day['attempts'] / $maxDailyAttempts) * 100))
                    : 0;
                return $day;
            });

            $wordStats = (clone $progressQuery)
                ->selectRaw('entry_id, COUNT(*) as attempts, SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct, MAX(created_at) as last_attempt_at')
                ->groupBy('entry_id')
                ->get();
            $wordProgress = $wordStats->map(function ($row) use ($entries): array {
                $entry = $entries->firstWhere('id', (int) $row->entry_id);
                $attempts = (int) $row->attempts;
                $correct = (int) $row->correct;
                return [
                    'entry_id' => (int) $row->entry_id,
                    'word' => (string) ($entry?->word ?? 'Assigned word'),
                    'category' => (string) ($entry?->category ?? 'Vocabulary'),
                    'attempts' => $attempts,
                    'accuracy' => $attempts > 0 ? (int) round(($correct / $attempts) * 100) : 0,
                    'last_attempt_at' => $row->last_attempt_at,
                ];
            })->filter(fn (array $item): bool => $item['entry_id'] > 0)->values();

            // Transparent spaced-review intervals: low accuracy returns sooner.
            // New assigned words are immediately available; no synthetic score is used.
            $wordProgressById = $wordProgress->keyBy('entry_id');
            $reviewQueue = $entries->map(function ($entry) use ($wordProgressById): array {
                $stats = $wordProgressById->get((int) $entry->id);
                if (!$stats) {
                    return [
                        'entry_id' => (int) $entry->id,
                        'word' => (string) $entry->word,
                        'category' => (string) ($entry->category ?? 'Vocabulary'),
                        'attempts' => 0,
                        'accuracy' => null,
                        'due_at' => now(),
                        'reason' => 'New word — start learning',
                        'is_due' => true,
                    ];
                }

                $intervalDays = match (true) {
                    $stats['accuracy'] < 50 => 1,
                    $stats['accuracy'] < 70 => 3,
                    $stats['accuracy'] < 85 => 7,
                    default => 14,
                };
                $dueAt = Carbon::parse($stats['last_attempt_at'])->addDays($intervalDays);
                return [
                    'entry_id' => (int) $entry->id,
                    'word' => (string) $entry->word,
                    'category' => (string) ($entry->category ?? 'Vocabulary'),
                    'attempts' => $stats['attempts'],
                    'accuracy' => $stats['accuracy'],
                    'due_at' => $dueAt,
                    'reason' => $stats['accuracy'] < 70
                        ? 'Needs reinforcement — '.$intervalDays.'-day review interval'
                        : 'Retention check — '.$intervalDays.'-day review interval',
                    'is_due' => $dueAt->lte(now()),
                ];
            })->filter(fn (array $item): bool => $item['is_due'])
              ->sortBy('due_at')
              ->take(8)
              ->values();

            // Recommendations use only server-graded IPA quiz attempts. Three
            // attempts are required before identifying a weak item.
            $weakWords = $wordProgress
                ->filter(fn (array $item): bool => $item['attempts'] >= 3 && $item['accuracy'] < 70)
                ->sortBy('accuracy')
                ->take(5)
                ->values();

            $accentRows = (clone $progressQuery)
                ->selectRaw('target_accent, COUNT(*) as attempts, SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct')
                ->whereIn('target_accent', ['uk', 'us'])
                ->groupBy('target_accent')
                ->get()
                ->keyBy('target_accent');
            foreach (['uk', 'us'] as $accent) {
                $row = $accentRows->get($accent);
                if ($row && (int) $row->attempts > 0) {
                    $accentAccuracy[$accent] = (int) round(((int) $row->correct / (int) $row->attempts) * 100);
                }
            }
        }

        if (Schema::hasTable('phonetic_speaking_recordings')) {
            $speakingStats = DB::table('phonetic_speaking_recordings')
                ->where('lms_driver', $this->catalog->driver())
                ->where('course_ref', $courseRef)
                ->where('user_id', (string) $request->user()->getAuthIdentifier())
                ->selectRaw("SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_count")
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count")
                ->selectRaw("AVG(CASE WHEN status = 'reviewed' THEN score ELSE NULL END) as average_score")
                ->first();
            $progress['speaking_reviewed'] = (int) ($speakingStats->reviewed_count ?? 0);
            $progress['speaking_pending'] = (int) ($speakingStats->pending_count ?? 0);
            $progress['speaking_average_score'] = $speakingStats->average_score !== null
                ? round((float) $speakingStats->average_score, 1)
                : null;
        }
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
            'progress' => $progress,
            'dailyActivity' => $dailyActivity,
            'weakWords' => $weakWords,
            'reviewQueue' => $reviewQueue,
            'accentAccuracy' => $accentAccuracy,
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
            'course_ref' => $courseRef,
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
