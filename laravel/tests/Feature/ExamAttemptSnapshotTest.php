<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamAttemptSnapshotTest extends TestCase
{
    public function test_web_attempt_uses_its_frozen_questions_and_grading_policy_after_exam_edits(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');

        [$examId, $questionId] = $this->createSnapshotExam();
        $page = $this->actingAs($student)->get("/exams/{$examId}/take")
            ->assertOk()
            ->assertSee('Original question prompt')
            ->assertDontSee('correct_option_index');

        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        $snapshot = json_decode((string) DB::table('exam_attempts')->where('id', $attemptId)->value('question_snapshot'), true);
        $this->assertSame('Original question prompt', $snapshot['questions'][0]['prompt']);
        $this->assertSame(0, $snapshot['questions'][0]['correct_option_index']);
        $this->assertEquals(2.0, $snapshot['questions'][0]['marks']);

        // Simulate an administrator editing the live question and exam settings
        // after the student has started. The in-progress attempt must not change.
        DB::table('exam_questions')->where('id', $questionId)->update([
            'prompt' => 'Changed question prompt',
            'options' => json_encode(['Changed A', 'Changed B', 'Changed C']),
            'correct_option_index' => 1,
            'marks' => 9,
            'negative_marks' => 4,
        ]);
        DB::table('exam_questions')->insert([
            'exam_id' => $examId,
            'prompt' => 'Question added after start',
            'options' => json_encode(['Yes', 'No']),
            'correct_option_index' => 0,
            'marks' => 100,
            'negative_marks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('exams')->where('id', $examId)->update([
            'duration_minutes' => 5,
            'pass_marks' => 50,
        ]);

        $this->actingAs($student)->get("/exams/{$examId}/take")
            ->assertOk()
            ->assertSee('Original question prompt')
            ->assertDontSee('Changed question prompt')
            ->assertDontSee('Question added after start');

        $this->actingAs($student)->post("/exams/{$examId}/attempt", [
            'attempt_id' => $attemptId,
            'answers' => [(string) $questionId => 0],
        ])->assertRedirect(route('portal.dashboard'));

        $attempt = DB::table('exam_attempts')->where('id', $attemptId)->first();
        $this->assertEquals(2, (float) $attempt->score_obtained);
        $this->assertEquals(2, (float) $attempt->total_marks);
        $this->assertTrue((bool) $attempt->passed);
    }

    public function test_mobile_api_attempt_grades_against_the_original_snapshot_and_hides_answer_key(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$examId, $questionId] = $this->createSnapshotExam();
        $token = $student->createToken('exam-snapshot-mobile')->plainTextToken;

        $start = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$examId}/attempts")
            ->assertOk()
            ->assertJsonMissingPath('questions.0.correct_option_index')
            ->assertJsonPath('questions.0.prompt', 'Original question prompt');

        $attemptId = (int) $start->json('attempt.id');
        $this->assertGreaterThan(0, $attemptId);

        DB::table('exam_questions')->where('id', $questionId)->update([
            'correct_option_index' => 1,
            'marks' => 9,
            'negative_marks' => 4,
        ]);
        DB::table('exam_questions')->insert([
            'exam_id' => $examId,
            'prompt' => 'Late added question',
            'options' => json_encode(['A', 'B']),
            'correct_option_index' => 0,
            'marks' => 50,
            'negative_marks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/my/exams/{$examId}/attempts/{$attemptId}/submit", [
                'answers' => [(string) $questionId => 0],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 2)
            ->assertJsonPath('data.total_marks', 2)
            ->assertJsonPath('data.passed', true);
    }

    private function createSnapshotExam(): array
    {
        $examId = DB::table('exams')->insertGetId([
            'title' => 'Immutable Snapshot Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 2,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $questionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId,
            'prompt' => 'Original question prompt',
            'options' => json_encode(['Original A', 'Original B']),
            'correct_option_index' => 0,
            'marks' => 2,
            'negative_marks' => 0.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$examId, $questionId];
    }
}
