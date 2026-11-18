<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseAssessmentsMobileApiTest extends TestCase
{
    public function test_mobile_course_assessment_requires_enrollment_and_returns_only_safe_attempt_snapshots(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'course-assessments']);

        $branchA = $this->createBranch('MOB-ASSESS-A');
        $branchB = $this->createBranch('MOB-ASSESS-B');
        $studentA = $this->createUserWithRole('student', $branchA);
        $studentB = $this->createUserWithRole('student', $branchB);
        $course = $this->createCourse($branchA, 'ASSESS-A');
        $set = $this->createSet($course, $branchA, 'Course Grammar Assessment');
        $this->createQuestion($set);

        $tokenA = $studentA->createToken('mobile-assessment-a')->plainTextToken;
        $tokenB = $studentB->createToken('mobile-assessment-b')->plainTextToken;

        $this->withToken($tokenA)->getJson('/api/v1/my-learning/assessments')
            ->assertOk()->assertJsonPath('data', []);
        $this->withToken($tokenA)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')
            ->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/v1/my-learning/assessments')
            ->assertOk()->assertJsonPath('data', []);
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')
            ->assertForbidden();

        DB::table('enrollments')->insert([
            'user_id' => $studentA->id,
            'course_id' => $course,
            'branch_id' => $branchA,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($tokenA)->getJson('/api/v1/my-learning/assessments')
            ->assertOk()->assertJsonPath('data.0.id', $set);

        $start = $this->withToken($tokenA)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')
            ->assertCreated()
            ->assertJsonMissingPath('questions.0.correct_option')
            ->assertJsonMissingPath('questions.0.correct_answer')
            ->assertJsonMissingPath('questions.0.explanation');

        $attemptId = (int) $start->json('attempt.id');
        $questionId = (int) $start->json('questions.0.id');
        $this->assertGreaterThan(0, $attemptId);
        $this->assertGreaterThan(0, $questionId);

        app('auth')->forgetGuards();
        $this->withToken($tokenA)->putJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/questions/{$questionId}", [
            'selected_option' => 1,
        ])->assertOk()->assertJson(['saved' => true]);

        $this->withToken($tokenA)->getJson("/api/v1/my-learning/assessments/attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('questions.0.selected_option', 1)
            ->assertJsonMissingPath('questions.0.correct_option');

        $resume = $this->withToken($tokenA)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')
            ->assertOk();
        $this->assertSame($attemptId, (int) $resume->json('attempt.id'));
        $this->assertSame(1, (int) $resume->json('questions.0.selected_option'));

        $this->withToken($tokenA)->postJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'submitted')
            ->assertJsonPath('attempt.auto_score', '2.00');

        app('auth')->forgetGuards();
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->getJson("/api/v1/my-learning/assessments/attempts/{$attemptId}")
            ->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($tokenA)->putJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/questions/{$questionId}", [
            'selected_option' => 0,
        ])->assertStatus(409);
    }

    public function test_attempt_deadline_is_clamped_to_availability_window_and_window_end_is_exclusive(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        $student = $this->createUserWithRole('student');
        $token = $student->createToken('mobile-assessment-window')->plainTextToken;

        $availableUntil = now()->addMinutes(5)->startOfSecond();
        $set = $this->createSet(null, null, 'Clamped Assessment', [
            'time_limit_minutes' => 60,
            'available_until' => $availableUntil,
        ]);
        $this->createQuestion($set);

        $start = $this->withToken($token)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')
            ->assertCreated();
        $deadline = \Carbon::parse($start->json('attempt.deadline_at'));
        $this->assertTrue($deadline->equalTo($availableUntil));

        $closedSet = $this->createSet(null, null, 'Closed Assessment', [
            'available_until' => $availableUntil,
        ]);
        $this->createQuestion($closedSet);
        $this->travelTo($availableUntil);

        $this->withToken($token)->postJson('/api/v1/my-learning/assessments/'.$closedSet.'/start')
            ->assertForbidden();
    }

    public function test_written_mobile_assessment_enters_manual_review_and_history_reflects_grading(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $token = $student->createToken('mobile-written-assessment')->plainTextToken;
        $set = $this->createSet(null, null, 'Written Assessment', ['max_attempts' => 1]);
        $question = $this->createQuestion($set, [
            'type' => 'written',
            'options' => null,
            'correct_option' => null,
            'correct_answer' => 'A complete sentence.',
            'question' => 'Write a complete sentence.',
            'marks' => 3,
        ]);

        $start = $this->withToken($token)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')
            ->assertCreated();
        $attemptId = (int) $start->json('attempt.id');
        $questionId = (int) $start->json('questions.0.id');

        $this->withToken($token)->putJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/questions/{$questionId}", [
            'answer_text' => 'This is my written response.',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'pending_review')
            ->assertJsonPath('attempt.final_score', null);

        $this->actingAs($admin)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
            'scores' => [$questionId => 2],
            'feedback' => [$questionId => 'Good response.'],
            'teacher_feedback' => 'Well done.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        app('auth')->forgetGuards();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/my-learning/assessments/history')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'graded')
            ->assertJsonPath('data.0.final_score', '2.00');
    }

    public function test_admin_cannot_import_questions_from_another_branch_into_a_course_assessment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'question-bank', 'course-assessments']);
        $admin = $this->createUserWithRole('admin');
        $branchA = $this->createBranch('MOB-SOURCE-A');
        $branchB = $this->createBranch('MOB-SOURCE-B');
        $course = $this->createCourse($branchA, 'SOURCE-A');

        $source = DB::table('question_bank_questions')->insertGetId([
            'branch_id' => $branchB,
            'exam_category' => 'bcs',
            'question_type' => 'mcq',
            'subject' => 'English',
            'topic' => 'Tense',
            'difficulty' => 'easy',
            'prompt' => 'Choose the past tense of go.',
            'options' => json_encode(['go', 'went', 'gone']),
            'correct_answer' => 'went',
            'explanation' => 'Past form.',
            'default_marks' => 2,
            'default_negative_marks' => 0,
            'tags' => json_encode(['tense']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('plugins.course-assessments.admin.store'), [
            'course_id' => $course,
            'title' => 'Cross Branch Import Must Fail',
            'status' => 'draft',
            'source_question_ids' => [$source],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('course_assessment_sets', ['title' => 'Cross Branch Import Must Fail']);
    }

    private function createBranch(string $code): int
    {
        return DB::table('branches')->insertGetId([
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCourse(int $branchId, string $suffix): int
    {
        return DB::table('courses')->insertGetId([
            'branch_id' => $branchId,
            'code' => 'MOB-'.$suffix,
            'title' => 'Mobile course '.$suffix,
            'slug' => 'mobile-course-'.$suffix,
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSet(?int $courseId, ?int $branchId, string $title, array $overrides = []): int
    {
        return DB::table('course_assessment_sets')->insertGetId(array_merge([
            'course_id' => $courseId,
            'branch_id' => $branchId,
            'title' => $title,
            'description' => 'Mobile assessment test.',
            'status' => 'published',
            'time_limit_minutes' => 30,
            'max_attempts' => 2,
            'questions_per_attempt' => null,
            'randomize_questions' => false,
            'available_from' => null,
            'available_until' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createQuestion(int $setId, array $overrides = []): int
    {
        return DB::table('course_assessment_questions')->insertGetId(array_merge([
            'assessment_set_id' => $setId,
            'source_question_id' => null,
            'type' => 'mcq',
            'question' => 'Select the correct answer.',
            'options' => json_encode(['A', 'B']),
            'correct_option' => 1,
            'correct_answer' => 'B',
            'explanation' => 'B is correct.',
            'difficulty' => 'easy',
            'tags' => json_encode([]),
            'marks' => 2,
            'position' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_only_the_assigned_course_teacher_can_grade_written_assessment_attempts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'course-assessments']);
        $branch = $this->createBranch('MOB-GRADE-A');
        $student = $this->createUserWithRole('student', $branch);
        $teacher = $this->createUserWithRole('teacher', $branch);
        $otherTeacher = $this->createUserWithRole('teacher', $branch);
        $course = $this->createCourse($branch, 'GRADE-A');

        DB::table('course_teacher_assignments')->insert([
            'course_id' => $course,
            'teacher_user_id' => $teacher->id,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $teacherRole = DB::table('roles')->where('slug', 'teacher')->value('id');
        $gradePermission = DB::table('permissions')->where('slug', 'course_assessments.grade')->value('id');
        $this->assertNotNull($teacherRole);
        $this->assertNotNull($gradePermission);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $teacherRole,
            'permission_id' => $gradePermission,
        ]);

        $set = $this->createSet($course, $branch, 'Teacher Graded Assessment', ['max_attempts' => 1]);
        $this->createQuestion($set, [
            'type' => 'written',
            'options' => null,
            'correct_option' => null,
            'correct_answer' => 'A complete sentence.',
            'question' => 'Write a complete sentence.',
            'marks' => 3,
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course,
            'branch_id' => $branch,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $student->createToken('mobile-teacher-grade')->plainTextToken;
        $start = $this->withToken($token)->postJson('/api/v1/my-learning/assessments/'.$set.'/start')->assertCreated();
        $attemptId = (int) $start->json('attempt.id');
        $questionId = (int) $start->json('questions.0.id');
        $this->withToken($token)->putJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/questions/{$questionId}", [
            'answer_text' => 'A complete sentence for grading.',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/my-learning/assessments/attempts/{$attemptId}/submit")
            ->assertOk()->assertJsonPath('attempt.status', 'pending_review');

        $this->actingAs($otherTeacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
            'scores' => [$questionId => 2],
        ])->assertForbidden();

        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
            'scores' => [$questionId => 2],
            'feedback' => [$questionId => 'Good.'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'graded',
            'manual_score' => 2,
            'final_score' => 2,
            'graded_by' => $teacher->id,
        ]);
    }

}
