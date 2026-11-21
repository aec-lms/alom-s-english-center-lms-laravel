<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Tests\TestCase;

class ExamAttemptSnapshotTest extends TestCase
{
    public function test_web_attempt_keeps_original_questions_marks_and_pass_rule_after_exam_is_edited(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();

        $page = $this->actingAs($student)->get("/exams/{$exam->id}/take")
            ->assertOk()
            ->assertSee('Original question text')
            ->assertSee('Original option B')
            ->assertSee('<fieldset', false)
            ->assertSee('aria-labelledby="exam-question-' . $question->id . '"', false)
            ->assertSee('focus-visible:outline', false)
            ->assertDontSee('correct_option_index');

        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        DB::table('exam_questions')->where('id', $question->id)->update([
            'prompt' => 'Changed question text',
            'options' => json_encode(['Changed option A', 'Changed option B', 'Changed option C']),
            'correct_option_index' => 0,
            'marks' => 50,
            'negative_marks' => 0,
        ]);
        DB::table('exams')->where('id', $exam->id)->update([
            'duration_minutes' => 5,
            'pass_marks' => 100,
        ]);
        ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'Question added after attempt start',
            'options' => ['Yes', 'No'],
            'correct_option_index' => 0,
            'marks' => 50,
        ]);

        $resumed = $this->actingAs($student)->get("/exams/{$exam->id}/take")
            ->assertOk()
            ->assertSee('Original question text')
            ->assertSee('Original option B')
            ->assertDontSee('Changed question text')
            ->assertDontSee('Question added after attempt start')
            ->assertSee('30 minutes')
            ->assertSee('Pass marks: 5');

        $this->actingAs($student)->post("/exams/{$exam->id}/attempt", [
            'attempt_id' => $attemptId,
            'answers' => [(string) $question->id => 1],
        ])->assertRedirect(route('portal.dashboard'));

        $attempt = ExamAttempt::query()->findOrFail($attemptId);
        $this->assertSame(5.0, (float) $attempt->score_obtained);
        $this->assertSame(5.0, (float) $attempt->total_marks);
        $this->assertTrue($attempt->passed);
        $this->assertSame('Original question text', $attempt->exam_snapshot['questions'][0]['prompt']);
        $this->assertSame(['Original option A', 'Original option B'], $attempt->exam_snapshot['questions'][0]['options']);
        $this->assertSame(1, $attempt->exam_snapshot['questions'][0]['correct_option_index']);
        $this->assertSame(1.0, (float) $attempt->exam_snapshot['questions'][0]['negative_marks']);

        $futureAttemptPage = $this->actingAs($student)->get("/exams/{$exam->id}/take")
            ->assertOk()
            ->assertSee('Changed question text')
            ->assertSee('Question added after attempt start')
            ->assertSee('5 minutes')
            ->assertSee('Pass marks: 100');
        preg_match('/name="attempt_id" value="([0-9]+)"/', $futureAttemptPage->getContent(), $futureMatch);
        $futureAttemptId = (int) ($futureMatch[1] ?? 0);
        $this->assertGreaterThan(0, $futureAttemptId);
        $futureSnapshot = ExamAttempt::query()->findOrFail($futureAttemptId)->exam_snapshot;
        $this->assertCount(2, $futureSnapshot['questions']);
        $this->assertSame('Changed question text', $futureSnapshot['questions'][0]['prompt']);
        $this->assertSame(5, $futureSnapshot['duration_minutes']);
        $this->assertSame(100.0, (float) $futureSnapshot['pass_marks']);
        $this->assertSame(30, $attempt->exam_snapshot['duration_minutes']);
        $this->assertSame(5.0, (float) $attempt->exam_snapshot['pass_marks']);
    }

    public function test_mobile_attempt_grades_from_frozen_snapshot_and_never_returns_answer_key(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();
        $token = $student->createToken('exam-snapshot-mobile')->plainTextToken;

        $start = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$exam->id}/attempts")
            ->assertOk()
            ->assertJsonPath('questions.0.prompt', 'Original question text')
            ->assertJsonMissingPath('questions.0.correct_option_index')
            ->assertJsonMissingPath('questions.0.negative_marks');

        $attemptId = (int) $start->json('attempt.id');
        $this->assertGreaterThan(0, $attemptId);

        DB::table('exam_questions')->where('id', $question->id)->update([
            'prompt' => 'Changed mobile question',
            'options' => json_encode(['New answer A', 'New answer B']),
            'correct_option_index' => 0,
            'marks' => 99,
            'negative_marks' => 0,
        ]);
        DB::table('exams')->where('id', $exam->id)->update(['pass_marks' => 99]);
        ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'New mobile question',
            'options' => ['A', 'B'],
            'correct_option_index' => 1,
            'marks' => 99,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$exam->id}/attempts/{$attemptId}/submit", [
                'answers' => [(string) $question->id => 1],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.total_marks', 5)
            ->assertJsonPath('data.passed', true);
    }

    public function test_legacy_pending_attempt_without_snapshot_returns_actionable_errors_and_is_not_regraded(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();

        $legacyAttempt = ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'started_at' => now(),
            'submitted_answers' => null,
            'exam_snapshot' => null,
            'score_obtained' => 0,
            'total_marks' => 0,
            'passed' => false,
            'submitted_at' => null,
        ]);

        $message = 'This existing exam attempt cannot be resumed safely because its saved question and scoring data is missing or invalid. Your attempt has not been submitted and your score has not changed. Please contact your instructor or school administrator for help.';

        $this->actingAs($student)->get("/exams/{$exam->id}/take")
            ->assertStatus(409)
            ->assertSee('We need to verify this attempt')
            ->assertSee($message)
            ->assertSee('contact your instructor or school administrator');

        $this->actingAs($student)->postJson("/exams/{$exam->id}/attempt/autosave", [
            'attempt_id' => $legacyAttempt->id,
            'answers' => [(string) $question->id => 1],
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'legacy_attempt_snapshot_unavailable')
            ->assertJsonPath('error.message', $message);

        $this->actingAs($student)->post("/exams/{$exam->id}/attempt", [
            'attempt_id' => $legacyAttempt->id,
            'answers' => [(string) $question->id => 1],
        ])->assertStatus(409)
            ->assertSee('We need to verify this attempt')
            ->assertSee($message);

        $token = $student->createToken('legacy-exam-snapshot-mobile')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$exam->id}/attempts")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'legacy_attempt_snapshot_unavailable')
            ->assertJsonPath('error.message', $message);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$exam->id}/attempts/{$legacyAttempt->id}/submit", [
                'answers' => [(string) $question->id => 1],
            ])->assertStatus(409)
            ->assertJsonPath('error.code', 'legacy_attempt_snapshot_unavailable')
            ->assertJsonPath('error.message', $message);

        $unchanged = $legacyAttempt->fresh();
        $this->assertNull($unchanged->submitted_at);
        $this->assertNull($unchanged->submitted_answers);
        $this->assertSame(0.0, (float) $unchanged->score_obtained);
        $this->assertSame(0.0, (float) $unchanged->total_marks);
        $this->assertFalse($unchanged->passed);
    }

    public function test_malformed_attempt_snapshots_fail_closed_without_changing_attempt_data(): void
    {
        // This matrix intentionally makes more requests than the production
        // per-student take limit; test the production throttle separately below.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();
        $token = $student->createToken('malformed-exam-snapshot-mobile')->plainTextToken;

        $validSnapshot = [
            'version' => 1,
            'duration_minutes' => 30,
            'pass_marks' => 5.0,
            'questions' => [[
                'id' => (int) $question->id,
                'prompt' => 'Original question text',
                'options' => ['Original option A', 'Original option B'],
                'correct_option_index' => 1,
                'marks' => 5.0,
                'negative_marks' => 1.0,
            ]],
        ];

        $missingQuestionId = $validSnapshot;
        unset($missingQuestionId['questions'][0]['id']);
        $missingPrompt = $validSnapshot;
        unset($missingPrompt['questions'][0]['prompt']);
        $missingOptions = $validSnapshot;
        unset($missingOptions['questions'][0]['options']);
        $missingCorrectIndex = $validSnapshot;
        unset($missingCorrectIndex['questions'][0]['correct_option_index']);
        $missingMarks = $validSnapshot;
        unset($missingMarks['questions'][0]['marks']);
        $missingDuration = $validSnapshot;
        unset($missingDuration['duration_minutes']);
        $missingPassMarks = $validSnapshot;
        unset($missingPassMarks['pass_marks']);
        $missingQuestions = $validSnapshot;
        unset($missingQuestions['questions']);
        $associativeQuestions = $validSnapshot;
        $associativeQuestions['questions'] = [1 => $associativeQuestions['questions'][0]];
        $emptyQuestions = $validSnapshot;
        $emptyQuestions['questions'] = [];
        $duplicateQuestionId = $validSnapshot;
        $duplicateQuestionId['questions'][] = $duplicateQuestionId['questions'][0];
        $associativeOptions = $validSnapshot;
        $associativeOptions['questions'][0]['options'] = [1 => 'Only one option'];
        $tooFewOptions = $validSnapshot;
        $tooFewOptions['questions'][0]['options'] = ['Only one option'];
        $invalidOptionValue = $validSnapshot;
        $invalidOptionValue['questions'][0]['options'][1] = 42;
        $invalidCorrectIndex = $validSnapshot;
        $invalidCorrectIndex['questions'][0]['correct_option_index'] = 2;
        $negativeMarks = $validSnapshot;
        $negativeMarks['questions'][0]['marks'] = -1;
        $negativePenalty = $validSnapshot;
        $negativePenalty['questions'][0]['negative_marks'] = -0.5;
        $nonFiniteNegativeMarks = $validSnapshot;
        $nonFiniteNegativeMarks['questions'][0]['negative_marks'] = 'INF';
        $nonFiniteMarks = $validSnapshot;
        $nonFiniteMarks['questions'][0]['marks'] = 'INF';
        $missingNegativeMarks = $validSnapshot;
        unset($missingNegativeMarks['questions'][0]['negative_marks']);
        $invalidPassMarks = $validSnapshot;
        $invalidPassMarks['pass_marks'] = 'NAN';
        $invalidDuration = $validSnapshot;
        $invalidDuration['duration_minutes'] = 0;
        $unsupportedVersion = $validSnapshot;
        $unsupportedVersion['version'] = 2;
        $invalidPrompt = $validSnapshot;
        $invalidPrompt['questions'][0]['prompt'] = 123;
        $overlongPrompt = $validSnapshot;
        $overlongPrompt['questions'][0]['prompt'] = str_repeat('x', 10001);
        $overlongOption = $validSnapshot;
        $overlongOption['questions'][0]['options'][0] = str_repeat('x', 301);
        $tooManyOptions = $validSnapshot;
        $tooManyOptions['questions'][0]['options'] = array_fill(0, 21, 'Option');
        $perQuestionMarksOverLimit = $validSnapshot;
        $perQuestionMarksOverLimit['questions'][0]['marks'] = 1000.01;
        $negativeMarksOverLimit = $validSnapshot;
        $negativeMarksOverLimit['questions'][0]['negative_marks'] = 1000.01;
        $passMarksOverLimit = $validSnapshot;
        $passMarksOverLimit['pass_marks'] = 100001;
        $durationOverLimit = $validSnapshot;
        $durationOverLimit['duration_minutes'] = 361;
        $aggregateOverLimit = $validSnapshot;
        $aggregateOverLimit['questions'] = [];
        for ($id = 1; $id <= 101; $id++) {
            $aggregateOverLimit['questions'][] = [
                'id' => $id,
                'prompt' => 'Bounded aggregate question',
                'options' => ['A', 'B'],
                'correct_option_index' => 0,
                'marks' => 1000.0,
                'negative_marks' => 0.0,
            ];
        }
        $questionCountOverLimit = $validSnapshot;
        $questionCountOverLimit['questions'] = [];
        for ($id = 1; $id <= 501; $id++) {
            $questionCountOverLimit['questions'][] = [
                'id' => $id,
                'prompt' => 'Bounded question count',
                'options' => ['A', 'B'],
                'correct_option_index' => 0,
                'marks' => 0.1,
                'negative_marks' => 0.0,
            ];
        }

        $malformedSnapshots = [
            'missing question ID' => $missingQuestionId,
            'missing prompt' => $missingPrompt,
            'missing options' => $missingOptions,
            'missing correct option index' => $missingCorrectIndex,
            'missing marks' => $missingMarks,
            'missing duration' => $missingDuration,
            'missing pass marks' => $missingPassMarks,
            'missing questions collection' => $missingQuestions,
            'associative questions collection' => $associativeQuestions,
            'empty questions collection' => $emptyQuestions,
            'duplicate question IDs' => $duplicateQuestionId,
            'associative options' => $associativeOptions,
            'fewer than two options' => $tooFewOptions,
            'non-string option' => $invalidOptionValue,
            'out-of-range correct option' => $invalidCorrectIndex,
            'negative marks' => $negativeMarks,
            'negative penalty' => $negativePenalty,
            'non-finite negative marks' => $nonFiniteNegativeMarks,
            'non-finite question marks' => $nonFiniteMarks,
            'missing negative marks' => $missingNegativeMarks,
            'non-finite pass marks' => $invalidPassMarks,
            'zero duration' => $invalidDuration,
            'unsupported version' => $unsupportedVersion,
            'non-string prompt' => $invalidPrompt,
            'overlong prompt' => $overlongPrompt,
            'overlong option' => $overlongOption,
            'too many options' => $tooManyOptions,
            'per-question marks over limit' => $perQuestionMarksOverLimit,
            'negative marks over limit' => $negativeMarksOverLimit,
            'pass marks over limit' => $passMarksOverLimit,
            'duration over limit' => $durationOverLimit,
            'aggregate marks over limit' => $aggregateOverLimit,
            'question count over limit' => $questionCountOverLimit,
        ];

        foreach ($malformedSnapshots as $case => $snapshot) {
            $savedAnswers = [(string) $question->id => 0];
            $attempt = ExamAttempt::query()->create([
                'exam_id' => $exam->id,
                'user_id' => $student->id,
                'started_at' => now(),
                'submitted_answers' => $savedAnswers,
                'exam_snapshot' => $snapshot,
                'score_obtained' => 2.5,
                'total_marks' => 5,
                'passed' => true,
                'submitted_at' => null,
            ]);

            $this->actingAs($student)->get("/exams/{$exam->id}/take")
                ->assertStatus(409)
                ->assertSee('saved question and scoring data is missing or invalid');

            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson("/api/v1/my/exams/{$exam->id}/attempts")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'legacy_attempt_snapshot_unavailable')
                ->assertJsonPath('error.message', 'This existing exam attempt cannot be resumed safely because its saved question and scoring data is missing or invalid. Your attempt has not been submitted and your score has not changed. Please contact your instructor or school administrator for help.');

            $this->actingAs($student)->postJson("/exams/{$exam->id}/attempt/autosave", [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ])->assertStatus(409)
                ->assertJsonPath('error.code', 'legacy_attempt_snapshot_unavailable');

            $this->actingAs($student)->post("/exams/{$exam->id}/attempt", [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ])->assertStatus(409)
                ->assertSee('saved question and scoring data is missing or invalid');

            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson("/api/v1/my/exams/{$exam->id}/attempts/{$attempt->id}/submit", [
                    'answers' => [(string) $question->id => 1],
                ])->assertStatus(409)
                ->assertJsonPath('error.code', 'legacy_attempt_snapshot_unavailable');

            $unchanged = $attempt->fresh();
            $this->assertNull($unchanged->submitted_at, "Malformed snapshot case '{$case}' must remain pending.");
            $this->assertSame($savedAnswers, $unchanged->submitted_answers, "Malformed snapshot case '{$case}' must preserve saved answers.");
            $this->assertSame(2.5, (float) $unchanged->score_obtained, "Malformed snapshot case '{$case}' must preserve its score.");
            $this->assertSame(5.0, (float) $unchanged->total_marks, "Malformed snapshot case '{$case}' must preserve total marks.");
            $this->assertTrue($unchanged->passed, "Malformed snapshot case '{$case}' must preserve its pass state.");
        }
    }

    public function test_student_exam_routes_use_independent_production_rate_limit_buckets(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);

        $expectedMiddleware = [
            'plugins.exams.take' => 'throttle:exam-take',
            'plugins.exams.history' => 'throttle:exam-history',
            'plugins.exams.attempt' => 'throttle:exam-submit',
            'plugins.exams.attempt.admin' => 'throttle:exam-submit',
            'plugins.exams.autosave' => 'throttle:exam-autosave',
        ];

        foreach ($expectedMiddleware as $routeName => $middleware) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Missing exam route [{$routeName}].");
            $this->assertContains($middleware, (array) $route->getAction('middleware'));
        }
    }

    public function test_primary_and_legacy_submit_routes_share_a_separate_abuse_limit_and_do_not_double_grade(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();

        $this->actingAs($student)->get("/exams/{$exam->id}/take")->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        RateLimiter::clear(md5('exam-submit' . 'submit:user:' . $student->getAuthIdentifier() . ':exam:' . $exam->id));
        $submitRoutes = [
            "/exams/{$exam->id}/attempt",
            "/admin/exams/{$exam->id}/attempt",
        ];

        for ($request = 0; $request < 5; $request++) {
            $response = $this->actingAs($student)->post($submitRoutes[$request % 2], [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ]);

            if ($request === 0) {
                $response->assertRedirect(route('portal.dashboard'));
            } else {
                $response->assertStatus(409);
            }
        }

        $this->actingAs($student)->post($submitRoutes[0], [
            'attempt_id' => $attempt->id,
            'answers' => [(string) $question->id => 1],
        ])->assertStatus(429);

        $attempt->refresh();
        $this->assertNotNull($attempt->submitted_at);
        $this->assertSame(5.0, (float) $attempt->score_obtained);
        $this->assertSame(5.0, (float) $attempt->total_marks);
        $this->assertTrue($attempt->passed);

        // The same student gets an independent submit bucket for another exam.
        [$otherExam, $otherQuestion] = $this->createSnapshotFixture();
        $this->actingAs($student)->get("/exams/{$otherExam->id}/take")->assertOk();
        $otherAttempt = ExamAttempt::query()
            ->where('exam_id', $otherExam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();
        $this->actingAs($student)->post("/exams/{$otherExam->id}/attempt", [
            'attempt_id' => $otherAttempt->id,
            'answers' => [(string) $otherQuestion->id => 1],
        ])->assertRedirect(route('portal.dashboard'));

        // A different student also receives an independent bucket for the original exam.
        $otherStudent = $this->createUserWithRole('student');
        $this->actingAs($otherStudent)->get("/exams/{$exam->id}/take")->assertOk();
        $otherStudentAttempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $otherStudent->id)
            ->firstOrFail();
        $this->actingAs($otherStudent)->post("/exams/{$exam->id}/attempt", [
            'attempt_id' => $otherStudentAttempt->id,
            'answers' => [(string) $question->id => 1],
        ])->assertRedirect(route('portal.dashboard'));
    }

    public function test_snapshot_validator_accepts_documented_upper_bounds_without_overflow(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();
        $token = $student->createToken('maximum-exam-snapshot-mobile')->plainTextToken;
        $exam->update(['duration_minutes' => 360, 'pass_marks' => 100000]);

        $questions = [];
        for ($index = 0; $index < 500; $index++) {
            $options = ['Boundary option A', 'Boundary option B'];
            $prompt = 'Boundary question ' . ($index + 1);
            if ($index === 0) {
                $options = array_fill(0, 20, str_repeat('O', 300));
                $prompt = str_repeat('Q', 10000);
            }

            $questions[] = [
                'id' => $index === 0 ? (int) $question->id : 10000 + $index,
                'prompt' => $prompt,
                'options' => $options,
                'correct_option_index' => 0,
                'marks' => $index < 100 ? 1000.0 : 0.0,
                'negative_marks' => 1000.0,
            ];
        }

        $snapshot = [
            'version' => 1,
            'duration_minutes' => 360,
            'pass_marks' => 100000.0,
            'questions' => $questions,
        ];

        $attempt = ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'started_at' => now(),
            'submitted_answers' => null,
            'exam_snapshot' => $snapshot,
            'score_obtained' => 0,
            'total_marks' => 0,
            'passed' => false,
            'submitted_at' => null,
        ]);

        $this->actingAs($student)->get("/exams/{$exam->id}/take")
            ->assertOk()
            ->assertSee(str_repeat('Q', 10000))
            ->assertSee('Boundary question 500');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$exam->id}/attempts")
            ->assertOk()
            ->assertJsonCount(500, 'questions')
            ->assertJsonMissingPath('questions.0.correct_option_index')
            ->assertJsonMissingPath('questions.0.negative_marks');

        $this->assertSame(500, count($attempt->fresh()->exam_snapshot['questions']));
        $this->assertSame(100000.0, (float) collect($attempt->fresh()->exam_snapshot['questions'])->sum('marks'));
        $this->assertSame(360, $attempt->fresh()->exam_snapshot['duration_minutes']);
        $this->assertSame(100000.0, (float) $attempt->fresh()->exam_snapshot['pass_marks']);
    }

    public function test_snapshot_migration_rollback_preserves_historical_attempt_evidence(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam] = $this->createSnapshotFixture();

        $this->actingAs($student)->get("/exams/{$exam->id}/take")->assertOk();
        $attempt = ExamAttempt::query()->where('exam_id', $exam->id)->where('user_id', $student->id)->firstOrFail();
        $snapshot = $attempt->exam_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertNotEmpty($snapshot['questions']);

        $migration = require base_path('plugins/modules/exam-management/Database/Migrations/2026_11_19_000002_add_exam_attempt_snapshots.php');
        $migration->down();

        $this->assertTrue(Schema::hasColumn('exam_attempts', 'exam_snapshot'));
        $this->assertSame($snapshot, $attempt->fresh()->exam_snapshot);
    }


    public function test_exam_authoring_rejects_out_of_range_correct_option_indices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $admin = $this->createUserWithRole('admin');
        [$exam] = $this->createSnapshotFixture();

        foreach ([-1, 2, 9] as $index) {
            $this->actingAs($admin)->post("/admin/exams/{$exam->id}/questions", [
                'prompt' => 'Invalid answer index question ' . $index,
                'options' => ['Option A', 'Option B'],
                'correct_option_index' => $index,
                'marks' => 5,
            ])->assertSessionHasErrors('correct_option_index');

            $this->assertSame(1, ExamQuestion::query()->where('exam_id', $exam->id)->count());
        }

        $this->actingAs($admin)->post("/admin/exams/{$exam->id}/questions", [
            'prompt' => 'Valid boundary answer index question',
            'options' => ['Option A', 'Option B'],
            'correct_option_index' => 1,
            'marks' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exam_questions', [
            'exam_id' => $exam->id,
            'prompt' => 'Valid boundary answer index question',
            'correct_option_index' => 1,
        ]);
    }


    private function createSnapshotFixture(): array
    {
        $exam = Exam::query()->create([
            'title' => 'Snapshot Grammar Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 5,
            'is_published' => true,
        ]);
        $question = ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'Original question text',
            'options' => ['Original option A', 'Original option B'],
            'correct_option_index' => 1,
            'marks' => 5,
            'negative_marks' => 1,
        ]);

        return [$exam, $question];
    }
}
