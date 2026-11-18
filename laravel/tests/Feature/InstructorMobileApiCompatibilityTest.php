<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstructorMobileApiCompatibilityTest extends TestCase
{
    public function test_instructor_mobile_api_returns_student_profiles_and_accepts_app_assignment_payload(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'course-management',
            'student-management',
            'attendance-management',
            'assignment-management',
            'mobile-app-api',
        ]);

        $teacher = $this->createUserWithRole('teacher');
        $studentUser = $this->createUserWithRole('student');
        DB::table('users')->where('id', $teacher->id)->update(['branch_id' => 1]);
        DB::table('users')->where('id', $studentUser->id)->update(['branch_id' => 1]);

        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => 1,
            'code' => 'IMAPI-01',
            'title' => 'Instructor API Course',
            'slug' => 'instructor-api-course',
            'description' => 'API acceptance course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchId = DB::table('batches')->insertGetId([
            'course_id' => $courseId,
            'branch_id' => 1,
            'name' => 'API Batch A',
            'capacity' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $courseId,
            'teacher_user_id' => $teacher->id,
            'assigned_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'branch_id' => 1,
            'student_code' => 'IMAPI-ST-01',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $studentUser->id,
            'course_id' => $courseId,
            'branch_id' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $teacher->createToken('instructor-mobile-api-test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)->getJson('/api/v1/instructor/batches/'.$batchId)
            ->assertOk()
            ->assertJsonPath('batch.course', 'Instructor API Course')
            ->assertJsonPath('data.0.student.id', $studentId)
            ->assertJsonPath('data.0.student.name', $studentUser->name);

        $this->withHeaders($headers)->getJson('/api/v1/instructor/courses/instructor-api-course/students')
            ->assertOk()
            ->assertJsonPath('data.0.student.id', $studentId);

        $this->withHeaders($headers)->postJson('/api/v1/instructor/batches/'.$batchId.'/assignments', [
            'title' => 'Offline worksheet',
            'instructions' => 'Complete all questions and submit your answers.',
            'due_at' => now()->addDay()->toIso8601String(),
            'max_marks' => 25,
            'allow_late' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('assignment_tasks', [
            'course_id' => $courseId,
            'created_by' => $teacher->id,
            'title' => 'Offline worksheet',
            'max_score' => 25,
            'status' => 'published',
        ]);

        $this->withHeaders($headers)->getJson('/api/v1/instructor/attendance/policy')
            ->assertOk()
            ->assertJsonPath('offline_sync_enabled', false);
    }

    public function test_instructor_notice_read_state_is_returned_and_counted_across_pages(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'mobile-app-api', 'notice-board']);
        $teacher = $this->createUserWithRole('teacher');
        DB::table('course_teacher_assignments')->insert([
            'course_id' => DB::table('courses')->insertGetId([
                'branch_id' => null,
                'code' => 'NOTICE-API',
                'title' => 'Notice API Course',
                'slug' => 'notice-api-course',
                'description' => null,
                'price' => 0,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'teacher_user_id' => $teacher->id,
            'assigned_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $noticeId = DB::table('notices')->insertGetId([
            'branch_id' => null,
            'title' => 'Instructor notice',
            'body' => 'Read this notice.',
            'audience_role' => 'teacher',
            'is_pinned' => false,
            'published_at' => now(),
            'created_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $teacher->createToken('instructor-notice-test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)->getJson('/api/v1/instructor/notices')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.is_read', false);

        $this->withHeaders($headers)->postJson('/api/v1/instructor/notices/'.$noticeId.'/read', [])
            ->assertOk();

        $this->withHeaders($headers)->getJson('/api/v1/instructor/notices')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0)
            ->assertJsonPath('data.0.is_read', true);
    }
}
