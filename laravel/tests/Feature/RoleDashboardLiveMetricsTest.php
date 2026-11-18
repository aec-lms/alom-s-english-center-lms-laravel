<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleDashboardLiveMetricsTest extends TestCase
{
    public function test_teacher_dashboard_includes_explicit_course_assignments_and_legacy_assignments(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $teacher = $this->createUserWithRole('teacher', null, 'assigned-teacher-' . bin2hex(random_bytes(3)) . '@aec.test');

        $assignedCourse = $this->createCourse('ASSIGNED-' . strtoupper(bin2hex(random_bytes(3))), 'Assigned Course');
        $legacyCourse = $this->createCourse('LEGACY-' . strtoupper(bin2hex(random_bytes(3))), 'Legacy Course', $teacher->name);
        $unassignedCourse = $this->createCourse('OTHER-' . strtoupper(bin2hex(random_bytes(3))), 'Other Course');
        $otherTeacher = $this->createUserWithRole('teacher', null, 'other-teacher-' . bin2hex(random_bytes(3)) . '@aec.test');
        $assignedToOtherTeacher = $this->createCourse('OTHERASSIGN-' . strtoupper(bin2hex(random_bytes(3))), 'Assigned Elsewhere', $teacher->name);

        DB::table('course_teacher_assignments')->insert([
            [
                'course_id' => $assignedCourse,
                'teacher_user_id' => $teacher->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => $assignedToOtherTeacher,
                'teacher_user_id' => $otherTeacher->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($teacher)->get('/dashboard')->assertOk();
        $courseIds = $response->viewData('courses')->pluck('id')->all();

        $this->assertContains($assignedCourse, $courseIds, 'Explicit course-teacher assignments must appear even when the legacy instructor name differs.');
        $this->assertContains($legacyCourse, $courseIds, 'Legacy instructor-name assignments must remain visible during migration.');
        $this->assertNotContains($unassignedCourse, $courseIds);
        $this->assertNotContains($assignedToOtherTeacher, $courseIds, 'A legacy name match must not override another teacher\'s normalized assignment.');
    }

    public function test_branch_manager_dashboard_counts_only_attendance_from_the_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'attendance-management']);

        $branchA = $this->createBranch('PORTAL-A');
        $branchB = $this->createBranch('PORTAL-B');
        $manager = $this->createUserWithRole('branch_manager', $branchA);

        DB::table('attendance_records')->insert([
            [
                'batch_id' => 101, 'student_id' => 1001, 'branch_id' => $branchA,
                'attendance_date' => now()->toDateString(), 'status' => 'present',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'batch_id' => 101, 'student_id' => 1002, 'branch_id' => $branchA,
                'attendance_date' => now()->toDateString(), 'status' => 'absent',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'batch_id' => 202, 'student_id' => 2001, 'branch_id' => $branchB,
                'attendance_date' => now()->toDateString(), 'status' => 'present',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($manager)->get('/dashboard')->assertOk();
        $metrics = $response->viewData('metrics');

        $this->assertSame(2, $metrics['attendance_today']);
        $this->assertSame(1, $metrics['present_today']);
    }

    private function createCourse(string $code, string $title, ?string $instructorName = null): int
    {
        $attributes = [
            'branch_id' => null,
            'code' => $code,
            'title' => $title,
            'slug' => strtolower($code),
            'description' => null,
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('courses', 'category')) {
            $attributes['category'] = 'General';
        }
        if (Schema::hasColumn('courses', 'instructor_name')) {
            $attributes['instructor_name'] = $instructorName;
        }

        return (int) DB::table('courses')->insertGetId($attributes);
    }

    private function createBranch(string $prefix): int
    {
        return (int) DB::table('branches')->insertGetId([
            'code' => $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => $prefix . ' Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
