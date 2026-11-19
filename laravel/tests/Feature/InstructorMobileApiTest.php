<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\TeacherManagement\Models\Teacher;
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

    public function test_instructor_can_manage_lessons_and_schedule_live_classes_for_assigned_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'mobile-app-api']);
        $teacher = $this->createUserWithRole('teacher');
        $teacher->forceFill(['branch_id' => 1])->save();
        $course = $this->course(1, 'lesson-live');
        $this->assignCourse($course, $teacher->id);
        $batch = DB::table('batches')->insertGetId([
            'course_id' => $course,
            'branch_id' => 1,
            'name' => 'Lesson and Live Batch',
            'capacity' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = $teacher->createToken('instructor-content-test')->plainTextToken;
        $slug = 'mobile-instructor-course-lesson-live';

        $created = $this->withToken($token)->postJson('/api/v1/instructor/courses/'.$slug.'/lessons', [
            'title' => 'Suffix and Prefix',
            'content' => 'Lesson notes.',
            'duration_minutes' => 25,
            'order' => 1,
            'video_url' => 'https://youtu.be/abcdefghijk',
            'is_preview' => true,
        ])->assertCreated()
            ->assertJsonPath('lesson.title', 'Suffix and Prefix')
            ->assertJsonPath('lesson.order', 1)
            ->assertJsonPath('lesson.is_preview', true);

        $lessonId = $created->json('lesson.id');
        $this->withToken($token)->getJson('/api/v1/instructor/courses/'.$slug.'/lessons')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($token)->putJson('/api/v1/instructor/courses/'.$slug.'/lessons/'.$lessonId, [
            'title' => 'Suffix and Prefix Updated',
            'content' => 'Updated lesson notes.',
            'duration_minutes' => 30,
            'order' => 2,
            'video_url' => null,
            'is_preview' => false,
        ])->assertOk()->assertJsonPath('lesson.order', 2);
        $this->assertDatabaseHas('course_lessons', ['id' => $lessonId, 'title' => 'Suffix and Prefix Updated', 'sort_order' => 2]);

        $scheduled = $this->withToken($token)->postJson('/api/v1/instructor/batches/'.$batch.'/live-classes', [
            'title' => 'Grammar live session',
            'description' => 'Bring your notes.',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addHour()->toIso8601String(),
            'platform' => 'google_meet',
        ])->assertCreated()->assertJsonPath('live_class.status', 'scheduled');

        $liveId = $scheduled->json('live_class.id');
        $this->withToken($token)->getJson('/api/v1/instructor/live-classes')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($token)->patchJson('/api/v1/instructor/live-classes/'.$liveId, [
            'status' => 'completed',
            'recording_url' => 'https://youtu.be/abcdefghijk',
            'recording_notes' => 'Revision recording.',
        ])->assertOk()->assertJsonPath('live_class.status', 'completed');

        $this->assertDatabaseHas('live_classes', ['id' => $liveId, 'status' => 'completed', 'recording_url' => 'https://youtu.be/abcdefghijk']);
    }

    public function test_instructor_api_uses_teacher_profile_branch_when_account_branch_is_stale(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'teacher-management', 'course-management', 'mobile-app-api']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'INSTRUCTOR-A-' . strtoupper(uniqid()),
            'name' => 'Instructor Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'INSTRUCTOR-B-' . strtoupper(uniqid()),
            'name' => 'Instructor Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = $this->createUserWithRole('teacher', (int) $branchB);
        Teacher::query()->create([
            'user_id' => $teacher->id,
            'branch_id' => $branchA,
            'employee_code' => 'TEACHER-' . strtoupper(uniqid()),
            'specialization' => 'English',
            'is_active' => true,
        ]);

        $branchACourse = $this->course((int) $branchA, 'profile-branch-a');
        $branchBCourse = $this->course((int) $branchB, 'profile-branch-b');
        $this->assignCourse($branchACourse, $teacher->id);
        $this->assignCourse($branchBCourse, $teacher->id);

        $token = $teacher->createToken('teacher-profile-branch-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/instructor/courses')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['title' => 'Mobile Instructor Course profile-branch-a'])
            ->assertJsonMissing(['title' => 'Mobile Instructor Course profile-branch-b']);

        $this->withToken($token)->getJson('/api/v1/instructor/courses/mobile-instructor-course-profile-branch-b/students')
            ->assertNotFound();
    }

}
