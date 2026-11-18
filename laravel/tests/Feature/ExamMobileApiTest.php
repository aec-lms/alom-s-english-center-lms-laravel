<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Tests\TestCase;

class ExamMobileApiTest extends TestCase
{
    public function test_student_can_start_and_submit_an_exam_through_mobile_api_without_receiving_answer_keys(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);

        $exam = Exam::query()->create([
            'title' => 'Mobile Grammar Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 5,
            'is_published' => true,
        ]);
        $question = ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'Choose the correct form.',
            'options' => ['has went', 'has gone', 'have went'],
            'correct_option_index' => 1,
            'marks' => 5,
        ]);

        $student = $this->createUserWithRole('student');
        $token = $student->createToken('student-exam-mobile')->plainTextToken;

        $this->getJson('/api/v1/my/exams')->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/my/exams')
            ->assertOk()
            ->assertJsonPath('data.0.id', $exam->id)
            ->assertJsonPath('data.0.title', 'Mobile Grammar Exam');

        $start = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/my/exams/' . $exam->id . '/attempts')
            ->assertOk()
            ->assertJsonPath('questions.0.id', $question->id)
            ->assertJsonPath('questions.0.prompt', 'Choose the correct form.')
            ->assertJsonMissingPath('questions.0.correct_option_index');

        $attemptId = (int) $start->json('attempt.id');
        $this->assertGreaterThan(0, $attemptId);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/my/exams/' . $exam->id . '/attempts/' . $attemptId . '/submit', [
                'answers' => [(string) $question->id => 1],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.total_marks', 5)
            ->assertJsonPath('data.passed', true);

        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attemptId,
            'user_id' => $student->id,
            'score_obtained' => 5,
            'passed' => true,
        ]);

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/my/exams/' . $exam->id . '/attempts/' . $attemptId . '/submit', [
                'answers' => [(string) $question->id => 1],
            ])
            ->assertStatus(409);
    }

    public function test_student_cannot_submit_another_students_exam_attempt(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);

        $exam = Exam::query()->create([
            'title' => 'Private Exam',
            'duration_minutes' => 20,
            'max_attempts' => 1,
            'pass_marks' => 1,
            'is_published' => true,
        ]);
        $question = ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'One question.',
            'options' => ['A', 'B'],
            'correct_option_index' => 0,
            'marks' => 1,
        ]);

        $owner = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student');
        $attempt = \Plugins\Modules\ExamManagement\Models\ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'user_id' => $owner->id,
            'started_at' => now(),
            'submitted_answers' => null,
            'score_obtained' => 0,
            'total_marks' => 0,
            'passed' => false,
        ]);

        $token = $other->createToken('other-student-exam-mobile')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit', [
                'answers' => [(string) $question->id => 0],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attempt->id,
            'user_id' => $owner->id,
            'submitted_at' => null,
        ]);
    }
}
