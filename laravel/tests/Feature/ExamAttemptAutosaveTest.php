<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamAttemptAutosaveTest extends TestCase
{
    public function test_student_can_autosave_partial_answers_and_restore_them_after_reload(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$examId, $questionId, $secondQuestionId] = $this->createExamFixture();

        $page = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        $this->actingAs($student)
            ->postJson("/exams/{$examId}/attempt/autosave", [
                'attempt_id' => $attemptId,
                'answers' => [$questionId => 1],
            ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $this->actingAs($student)
            ->postJson("/exams/{$examId}/attempt/autosave", [
                'attempt_id' => $attemptId,
                'answers' => [$secondQuestionId => 0],
            ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $saved = json_decode((string) DB::table('exam_attempts')->where('id', $attemptId)->value('submitted_answers'), true);
        $this->assertSame(1, $saved[(string) $questionId] ?? null);
        $this->assertSame(0, $saved[(string) $secondQuestionId] ?? null);

        $this->actingAs($student)
            ->get("/exams/{$examId}/take")
            ->assertOk()
            ->assertSee('checked', false)
            ->assertSee('Answers save automatically as you choose them.');
    }

    public function test_autosave_rejects_foreign_attempts_and_question_ids_outside_the_exam(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');
        [$examId, $questionId] = $this->createExamFixture();

        $page = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        $this->actingAs($otherStudent)
            ->postJson("/exams/{$examId}/attempt/autosave", [
                'attempt_id' => $attemptId,
                'answers' => [$questionId => 0],
            ])
            ->assertNotFound();

        $this->actingAs($student)
            ->postJson("/exams/{$examId}/attempt/autosave", [
                'attempt_id' => $attemptId,
                'answers' => [999999 => 0],
            ])
            ->assertUnprocessable();
    }

    public function test_autosave_cannot_change_answers_after_the_attempt_deadline(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$examId, $questionId] = $this->createExamFixture();

        $page = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        DB::table('exam_attempts')->where('id', $attemptId)->update([
            'started_at' => now()->subMinutes(31),
        ]);

        $this->actingAs($student)
            ->postJson("/exams/{$examId}/attempt/autosave", [
                'attempt_id' => $attemptId,
                'answers' => [$questionId => 0],
            ])
            ->assertStatus(409);

        $this->assertSame(
            null,
            DB::table('exam_attempts')->where('id', $attemptId)->value('submitted_answers')
        );
    }

    private function createExamFixture(): array
    {
        $examId = DB::table('exams')->insertGetId([
            'title' => 'Autosave Grammar Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 1,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $questionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId,
            'prompt' => 'Choose the correct article.',
            'options' => json_encode(['a', 'an', 'the']),
            'correct_option_index' => 1,
            'marks' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondQuestionId = DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId,
            'prompt' => 'Choose the correct pronoun.',
            'options' => json_encode(['he', 'they']),
            'correct_option_index' => 0,
            'marks' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$examId, $questionId, $secondQuestionId];
    }

    public function test_autosave_is_rejected_at_the_exact_attempt_deadline(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$examId, $questionId] = $this->createExamFixture();

        $page = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        $deadline = now()->startOfSecond();
        DB::table('exam_attempts')->where('id', $attemptId)->update([
            'started_at' => $deadline->copy()->subMinutes(30),
        ]);
        $this->travelTo($deadline);

        $this->actingAs($student)
            ->postJson("/exams/{$examId}/attempt/autosave", [
                'attempt_id' => $attemptId,
                'answers' => [$questionId => 0],
            ])
            ->assertStatus(409);

        $this->assertNull(DB::table('exam_attempts')->where('id', $attemptId)->value('submitted_answers'));
    }

    public function test_submission_at_the_exact_attempt_deadline_is_recorded_as_expired(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$examId, $questionId] = $this->createExamFixture();

        $page = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $page->getContent(), $match);
        $attemptId = (int) ($match[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        $deadline = now()->startOfSecond();
        DB::table('exam_attempts')->where('id', $attemptId)->update([
            'started_at' => $deadline->copy()->subMinutes(30),
        ]);
        $this->travelTo($deadline);

        $this->actingAs($student)->post("/exams/{$examId}/attempt", [
            'attempt_id' => $attemptId,
            'answers' => [$questionId => 1],
        ])->assertRedirect();

        $attempt = DB::table('exam_attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attempt->submitted_at);
        $this->assertEquals(0, (float) $attempt->score_obtained);
        $this->assertFalse((bool) $attempt->passed);
    }

    public function test_exam_submission_window_is_closed_at_its_exact_end_time(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$examId] = $this->createExamFixture();

        $end = now()->startOfSecond();
        DB::table('exams')->where('id', $examId)->update(['ends_at' => $end]);
        $this->travelTo($end);

        $this->actingAs($student)->get("/exams/{$examId}/take")->assertForbidden();
    }

}
