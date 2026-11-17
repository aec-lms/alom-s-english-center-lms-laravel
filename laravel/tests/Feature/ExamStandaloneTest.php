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

        $this->actingAs($student)
            ->get("/exams/{$examId}/take")
            ->assertOk()
            ->assertSee('Choose the correct verb.')
            ->assertSee('Submit Exam')
            ->assertDontSee('correct_option_index');

        $this->actingAs($student)
            ->post("/exams/{$examId}/attempt", ['answers' => [$questionId => 0]])
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('exam_attempts', [
            'exam_id' => $examId,
            'user_id' => $student->id,
            'score_obtained' => 2,
            'total_marks' => 2,
            'passed' => true,
        ]);
    }
}
