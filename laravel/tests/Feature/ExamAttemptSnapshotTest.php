<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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

        $message = 'This existing exam attempt cannot be resumed safely because its original question set is unavailable. Your attempt has not been submitted and your score has not changed. Please contact your instructor or school administrator for help.';

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

        $malformedSnapshots = [
            'missing question ID' => $missingQuestionId,
            'duplicate question IDs' => $duplicateQuestionId,
            'associative options' => $associativeOptions,
            'fewer than two options' => $tooFewOptions,
            'non-string option' => $invalidOptionValue,
            'out-of-range correct option' => $invalidCorrectIndex,
            'negative marks' => $negativeMarks,
            'non-finite negative marks' => $nonFiniteNegativeMarks,
            'non-finite question marks' => $nonFiniteMarks,
            'missing negative marks' => $missingNegativeMarks,
            'non-finite pass marks' => $invalidPassMarks,
            'zero duration' => $invalidDuration,
            'unsupported version' => $unsupportedVersion,
            'non-string prompt' => $invalidPrompt,
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
