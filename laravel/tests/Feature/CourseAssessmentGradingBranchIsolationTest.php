<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Tests\\TestCase;

final class CourseAssessmentGradingBranchIsolationTest extends TestCase
{
    private function setupAssessmentPlugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'teacher-management', 'course-management', 'course-assessments']);
        $roleId = DB::table('roles')->where('slug', 'teacher')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'course_assessments.grade')->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    private function createBranch(string $suffix): int
    {
        return (int) DB::table('branches')->insertGetId([
            'code' => 'ASSESS-GRADE-' . $suffix . '-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Assessment Grading ' . $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTeacher(?int $userBranchId, ?int $profileBranchId): object
    {
        $teacher = $this->createUserWithRole('teacher', $userBranchId);
        DB::table('teachers')->insert([
            'user_id' => $teacher->id,
            'branch_id' => $profileBranchId,
            'employee_code' => 'ASSESS-TEACHER-' . strtoupper(bin2hex(random_bytes(5))),
            'specialization' => 'English',
            'is_active' => true,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $teacher;
    }

    private function createCourse(?int $branchId): int
    {
        return (int) DB::table('courses')->insertGetId([
            'branch_id' => $branchId,
            'code' => 'ASSESS-COURSE-' . strtoupper(bin2hex(random_bytes(4))),
            'title' => 'Branch Assessment Course',
            'slug' => 'branch-assessment-' . strtolower(bin2hex(random_bytes(4))),
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPendingAttempt(int $courseId, ?int $branchId, string $title): int
    {
        $setId = (int) DB::table('course_assessment_sets')->insertGetId([
            'course_id' => $courseId,
            'branch_id' => $branchId,
            'title' => $title,
            'description' => null,
            'status' => 'published',
            'time_limit_minutes' => 30,
            'max_attempts' => 1,
            'questions_per_attempt' => null,
            'randomize_questions' => false,
            'available_from' => null,
            'available_until' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', $branchId);
        $attemptId = (int) DB::table('course_assessment_attempts')->insertGetId([
            'assessment_set_id' => $setId,
            'course_id' => $courseId,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'pending_review',
            'started_at' => now()->subMinutes(10),
            'deadline_at' => null,
            'submitted_at' => now(),
            'auto_score' => 0,
            'manual_score' => 0,
            'max_score' => 5,
            'final_score' => null,
            'graded_by' => null,
            'graded_at' => null,
            'teacher_feedback' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_assessment_attempt_questions')->insert([
            'attempt_id' => $attemptId,
            'source_question_id' => null,
            'type' => 'written',
            'question' => 'Write a complete sentence.',
            'options' => null,
            'correct_option' => null,
            'correct_answer' => 'A complete sentence.',
            'explanation' => null,
            'marks' => 5,
            'position' => 10,
            'selected_option' => null,
            'answer_text' => 'This is a complete sentence.',
            'is_correct' => null,
            'marks_awarded' => 0,
            'manual_feedback' => null,
            'graded_by' => null,
            'graded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attemptId;
    }

    private function assignTeacher(int $teacherId, int $courseId): void
    {
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $courseId,
            'teacher_user_id' => $teacherId,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_branch_a_teacher_cannot_list_or_grade_branch_b_assessment_even_with_stale_assignment(): void
    {
        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('A');
        $branchB = $this->createBranch('B');
        $teacher = $this->createTeacher($branchA, $branchA);
        $courseB = $this->createCourse($branchB);
        $attemptId = $this->createPendingAttempt($courseB, $branchB, 'Secret Branch B Assessment');
        $this->assignTeacher((int) $teacher->id, $courseB);

        $this->actingAs($teacher)
            ->get(route('plugins.course-assessments.grading.index'))
            ->assertOk()
            ->assertDontSee('Secret Branch B Assessment');

        $questionId = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $attemptId)->value('id');
        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
            'scores' => [$questionId => 4],
            'feedback' => [$questionId => 'Unauthorized'],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'pending_review',
            'manual_score' => 0,
            'graded_by' => null,
        ]);
    }

    public function test_teacher_can_grade_same_branch_course_and_global_teacher_is_limited_to_global_course(): void
    {
        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('A');
        $teacher = $this->createTeacher($branchA, $branchA);
        $courseA = $this->createCourse($branchA);
        $attemptA = $this->createPendingAttempt($courseA, $branchA, 'Branch A Assessment');
        $this->assignTeacher((int) $teacher->id, $courseA);

        $questionA = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $attemptA)->value('id');
        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptA), [
            'scores' => [$questionA => 4],
            'feedback' => [$questionA => 'Good work.'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptA,
            'status' => 'graded',
            'manual_score' => 4,
            'graded_by' => $teacher->id,
        ]);

        $globalTeacher = $this->createTeacher(null, null);
        $globalCourse = $this->createCourse(null);
        $globalAttempt = $this->createPendingAttempt($globalCourse, null, 'Global Assessment');
        $this->assignTeacher((int) $globalTeacher->id, $globalCourse);

        $this->actingAs($globalTeacher)
            ->get(route('plugins.course-assessments.grading.index'))
            ->assertOk()
            ->assertSee('Global Assessment');
    }
}
