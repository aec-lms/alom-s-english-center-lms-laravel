<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstructorMobileApiTest extends TestCase
{
    private function course(int $branchId = 1, string $suffix = 'one'): int
    {
        return DB::table('courses')->insertGetId([
            'branch_id' => $branchId,
            'code' => 'MOB-'.strtoupper($suffix),
            'title' => 'Mobile Instructor Course '.$suffix,
            'slug' => 'mobile-instructor-course-'.$suffix,
            'description' => 'Test course.',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignCourse(int $courseId, int $teacherId): void
    {
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $courseId,
            'teacher_user_id' => $teacherId,
            'assigned_by' => $teacherId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_instructor_api_only_exposes_explicitly_assigned_courses_and_students(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'mobile-app-api']);
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $teacher->forceFill(['branch_id' => 1])->save();
        $assigned = $this->course(1, 'assigned');
        $unassigned = $this->course(1, 'unassigned');
        $this->assignCourse($assigned, $teacher->id);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $assigned,
            'branch_id' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $teacher->createToken('instructor-api-test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/instructor/courses')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $assigned);

        $this->withToken($token)->getJson('/api/v1/instructor/courses/mobile-instructor-course-assigned/students')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $student->id);

        $this->withToken($token)->getJson('/api/v1/instructor/courses/mobile-instructor-course-unassigned/students')
            ->assertNotFound();
    }

    public function test_instructor_can_create_and_grade_assignments_only_for_assigned_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'assignment-management', 'mobile-app-api']);
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $teacher->forceFill(['branch_id' => 1])->save();
        $course = $this->course(1, 'assignment');
        $this->assignCourse($course, $teacher->id);
        $batch = DB::table('batches')->insertGetId([
            'course_id' => $course,
            'branch_id' => 1,
            'name' => 'Instructor Test Batch',
            'capacity' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $teacher->createToken('instructor-assignment-test')->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/v1/instructor/batches/'.$batch.'/assignments', [
            'title' => 'Grammar homework',
            'instructions' => 'Complete the worksheet.',
            'due_at' => now()->addDays(2)->toIso8601String(),
            'max_score' => 20,
            'allow_late' => false,
        ])->assertCreated();

        $assignmentId = $created->json('assignment.id');
        $submissionId = DB::table('assignment_submissions')->insertGetId([
            'assignment_id' => $assignmentId,
            'student_id' => $student->id,
            'answer_text' => 'Completed.',
            'status' => 'submitted',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/v1/instructor/assignments/'.$assignmentId.'/submissions')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->withToken($token)->patchJson('/api/v1/instructor/assignments/'.$assignmentId.'/submissions/'.$submissionId, [
            'score' => 18,
            'feedback' => 'Good work.',
        ])->assertOk();

        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submissionId,
            'status' => 'graded',
            'score' => 18,
            'graded_by' => $teacher->id,
        ]);
    }

    public function test_student_cannot_use_instructor_endpoints(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'mobile-app-api']);
        $student = $this->createUserWithRole('student');
        $token = $student->createToken('student-instructor-deny')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/instructor/courses')->assertForbidden();
    }
}
