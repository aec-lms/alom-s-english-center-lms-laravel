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

    public function test_legacy_pending_attempt_without_snapshot_is_not_silently_regraded(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam] = $this->createSnapshotFixture();

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

        $this->actingAs($student)->get("/exams/{$exam->id}/take")
            ->assertStatus(409)
            ->assertSee('cannot be safely resumed or graded');

        $this->assertDatabaseHas('exam_attempts', [
            'id' => $legacyAttempt->id,
            'submitted_at' => null,
            'score_obtained' => 0,
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
