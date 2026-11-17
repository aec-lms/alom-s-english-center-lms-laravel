<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExamStandaloneTest extends TestCase
{
    public function test_general_exam_can_be_created_without_course_or_branch_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/exams', [
            'title' => 'General Grammar Assessment',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 20,
            'is_published' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exams', [
            'title' => 'General Grammar Assessment',
            'course_id' => null,
            'branch_id' => null,
            'is_published' => true,
        ]);
    }

    public function test_student_can_open_exam_take_ui_and_submit_server_graded_answers(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');

        $examId = \Illuminate\Support\Facades\DB::table('exams')->insertGetId([
            'title' => 'Student Grammar Exam',
            'duration_minutes' => 20,
            'max_attempts' => 2,
            'pass_marks' => 1,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $questionId = \Illuminate\Support\Facades\DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId,
            'prompt' => 'Choose the correct verb.',
            'options' => json_encode(['is', 'are']),
            'correct_option_index' => 0,
            'marks' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)
            ->get("/exams/{$examId}/take")
            ->assertOk()
            ->assertSee('Choose the correct verb.')
            ->assertSee('Submit Exam')
            ->assertSee('Time remaining:')
            ->assertDontSee('correct_option_index');

        preg_match('/name="attempt_id" value="([0-9]+)"/', $response->getContent(), $attemptMatch);
        $this->assertNotEmpty($attemptMatch[1] ?? null, 'The exam form must carry its server-created attempt ID.');

        $this->actingAs($student)
            ->post("/exams/{$examId}/attempt", ['attempt_id' => (int) $attemptMatch[1], 'answers' => [$questionId => 0]])
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('exam_attempts', [
            'exam_id' => $examId,
            'user_id' => $student->id,
            'score_obtained' => 2,
            'total_marks' => 2,
            'passed' => true,
        ]);

        $secondAttemptPage = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $secondAttemptPage->getContent(), $secondAttemptMatch);
        $secondAttemptId = (int) ($secondAttemptMatch[1] ?? 0);
        $this->assertGreaterThan(0, $secondAttemptId);
        $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        $this->assertDatabaseCount('exam_attempts', 2);

        $this->actingAs($student)->post("/exams/{$examId}/attempt", [
            'attempt_id' => $secondAttemptId,
            'answers' => [$questionId => 0],
        ])->assertRedirect(route('portal.dashboard'));
        $this->actingAs($student)->get("/exams/{$examId}/take")->assertForbidden();
    }

    public function test_expired_exam_attempt_is_closed_with_zero_marks_and_cannot_be_replayed(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');

        $examId = \Illuminate\\Support\\Facades\\DB::table('exams')->insertGetId([
            'title' => 'Timed Exam', 'duration_minutes' => 5, 'max_attempts' => 1,
            'pass_marks' => 1, 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $questionId = \Illuminate\\Support\\Facades\\DB::table('exam_questions')->insertGetId([
            'exam_id' => $examId, 'prompt' => 'Timed question?', 'options' => json_encode(['A', 'B']),
            'correct_option_index' => 0, 'marks' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->get("/exams/{$examId}/take")->assertOk();
        preg_match('/name="attempt_id" value="([0-9]+)"/', $response->getContent(), $attemptMatch);
        $attemptId = (int) ($attemptMatch[1] ?? 0);
        $this->assertGreaterThan(0, $attemptId);

        \Illuminate\\Support\\Facades\\DB::table('exam_attempts')->where('id', $attemptId)->update([
            'started_at' => now()->subMinutes(6),
        ]);

        $this->actingAs($student)->post("/exams/{$examId}/attempt", [
            'attempt_id' => $attemptId,
            'answers' => [$questionId => 0],
        ])->assertRedirect(route('portal.dashboard'))->assertSessionHasErrors('exam');

        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attemptId,
            'score_obtained' => 0, 'total_marks' => 2, 'passed' => false,
        ]);
        $this->actingAs($student)->get("/exams/{$examId}/take")->assertForbidden();
    }
}
