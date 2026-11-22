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

        $activeStudent = $this->createUserWithRole('student', null, 'active-student-' . bin2hex(random_bytes(3)) . '@aec.test');
        $secondActiveStudent = $this->createUserWithRole('student', null, 'second-active-student-' . bin2hex(random_bytes(3)) . '@aec.test');
        $pendingStudent = $this->createUserWithRole('student', null, 'pending-student-' . bin2hex(random_bytes(3)) . '@aec.test');
        DB::table('enrollments')->insert([
            ['user_id' => $activeStudent->id, 'course_id' => $assignedCourse, 'branch_id' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $activeStudent->id, 'course_id' => $legacyCourse, 'branch_id' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $secondActiveStudent->id, 'course_id' => $assignedCourse, 'branch_id' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $pendingStudent->id, 'course_id' => $assignedCourse, 'branch_id' => null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($teacher)->get('/dashboard')->assertOk();
        $courseIds = $response->viewData('courses')->pluck('id')->all();

        $this->assertContains($assignedCourse, $courseIds, 'Explicit course-teacher assignments must appear even when the legacy instructor name differs.');
        $this->assertContains($legacyCourse, $courseIds, 'Legacy instructor-name assignments must remain visible during migration.');
        $this->assertNotContains($unassignedCourse, $courseIds);
        $this->assertNotContains($assignedToOtherTeacher, $courseIds, 'A legacy name match must not override another teacher\'s normalized assignment.');
        $this->assertSame(2, $response->viewData('metrics')['active_students'], 'Active student counts must be distinct across the teacher\'s assigned courses and exclude pending enrollments.');

        $legacyCollisionTeacher = $this->createUserWithRole('teacher', null, 'legacy-collision-' . bin2hex(random_bytes(3)) . '@aec.test');
        $legacyCollisionTeacher->forceFill(['name' => $otherTeacher->name])->save();
        $unassignedLegacyCollision = $this->createCourse('LEGACYCOLLISION-' . strtoupper(bin2hex(random_bytes(3))), 'Legacy Collision', $otherTeacher->name);
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $unassignedLegacyCollision,
            'teacher_user_id' => $otherTeacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $collisionResponse = $this->actingAs($legacyCollisionTeacher)->get('/dashboard')->assertOk();
        $this->assertNotContains(
            $unassignedLegacyCollision,
            $collisionResponse->viewData('courses')->pluck('id')->all(),
            'Legacy name matching must never reveal a course assigned to another teacher, even when this teacher has no normalized assignments.'
        );
    }

    public function test_branch_manager_dashboard_counts_only_attendance_from_the_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'attendance-management', 'fees-management']);

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

        DB::table('fee_invoices')->insert([
            [
                'invoice_number' => 'PORTAL-A-' . strtoupper(bin2hex(random_bytes(3))),
                'student_id' => null, 'branch_id' => $branchA, 'fee_category_id' => null,
                'subtotal_amount' => 500, 'discount_amount' => 0, 'net_amount' => 500, 'paid_amount' => 500,
                'currency' => 'BDT', 'status' => 'paid', 'due_date' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'invoice_number' => 'PORTAL-B-' . strtoupper(bin2hex(random_bytes(3))),
                'student_id' => null, 'branch_id' => $branchB, 'fee_category_id' => null,
                'subtotal_amount' => 900, 'discount_amount' => 0, 'net_amount' => 900, 'paid_amount' => 900,
                'currency' => 'BDT', 'status' => 'paid', 'due_date' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($manager)->get('/dashboard')->assertOk();
        $metrics = $response->viewData('metrics');

        $this->assertSame(2, $metrics['attendance_today']);
        $this->assertSame(1, $metrics['present_today']);
        $this->assertSame(500.0, $metrics['collected_revenue'], 'Branch managers must not see revenue from other branches.');
    }

    public function test_teacher_dashboard_surfaces_only_the_teachers_assignment_workload(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management', 'course-management']);

        $teacher = $this->createUserWithRole('teacher', null, 'assignment-teacher-' . bin2hex(random_bytes(3)) . '@aec.test');
        $otherTeacher = $this->createUserWithRole('teacher', null, 'assignment-other-' . bin2hex(random_bytes(3)) . '@aec.test');
        $assignment = DB::table('assignment_tasks')->insertGetId([
            'branch_id' => null,
            'course_id' => null,
            'created_by' => $teacher->id,
            'title' => 'Teacher-owned assignment',
            'instructions' => 'Complete the practice task.',
            'due_at' => now()->addDays(2),
            'max_score' => 100,
            'allow_late' => false,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assignment_tasks')->insert([
            'branch_id' => null,
            'course_id' => null,
            'created_by' => $otherTeacher->id,
            'title' => 'Other teacher assignment',
            'instructions' => 'Private to another teacher.',
            'due_at' => now()->addDays(2),
            'max_score' => 100,
            'allow_late' => false,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assignment_submissions')->insert([
            [
                'assignment_id' => $assignment,
                'student_id' => $this->createUserWithRole('student')->id,
                'answer_text' => 'Submitted work',
                'submitted_at' => now(),
                'status' => 'submitted',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'assignment_id' => $assignment,
                'student_id' => $this->createUserWithRole('student')->id,
                'answer_text' => 'Already graded',
                'submitted_at' => now(),
                'status' => 'graded',
                'score' => 80,
                'graded_by' => $teacher->id,
                'graded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($teacher)->get('/dashboard')->assertOk();
        $this->assertSame(1, $response->viewData('metrics')['created_assignments']);
        $this->assertSame(1, $response->viewData('metrics')['pending_submissions']);
        $response->assertSee('Assignment Inbox')
            ->assertSee(route('plugins.assignments.index'));
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
