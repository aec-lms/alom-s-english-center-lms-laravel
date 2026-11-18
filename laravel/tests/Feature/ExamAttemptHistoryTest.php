<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamAttemptHistoryTest extends TestCase
{
    public function test_student_sees_only_their_own_attempt_summaries(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');

        $examId = DB::table('exams')->insertGetId([
            'title' => 'History Privacy Exam',
            'duration_minutes' => 30,
            'max_attempts' => 3,
            'pass_marks' => 5,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_attempts')->insert([
            [
                'exam_id' => $examId,
                'user_id' => $student->id,
                'started_at' => now()->subMinutes(20),
                'submitted_answers' => json_encode(['1' => 0]),
                'score_obtained' => 8,
                'total_marks' => 10,
                'passed' => true,
                'submitted_at' => now()->subMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exam_id' => $examId,
                'user_id' => $otherStudent->id,
                'started_at' => now()->subMinutes(15),
                'submitted_answers' => json_encode(['1' => 1]),
                'score_obtained' => 99,
                'total_marks' => 100,
                'passed' => true,
                'submitted_at' => now()->subMinutes(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($student)
            ->get("/exams/{$examId}/attempts")
            ->assertOk()
            ->assertSee('Attempts (1)')
            ->assertSee('8.00 / 10.00')
            ->assertDontSee('99.00 / 100.00')
            ->assertDontSee('submitted_answers')
            ->assertDontSee('correct_answer');

        $this->actingAs($otherStudent)
            ->get("/exams/{$examId}/attempts")
            ->assertOk()
            ->assertSee('99.00 / 100.00')
            ->assertDontSee('8.00 / 10.00');
    }

    public function test_non_students_cannot_open_student_attempt_history(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $admin = $this->createUserWithRole('admin');
        $examId = DB::table('exams')->insertGetId([
            'title' => 'Private History',
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'pass_marks' => 5,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get("/exams/{$examId}/attempts")->assertForbidden();
    }
}
