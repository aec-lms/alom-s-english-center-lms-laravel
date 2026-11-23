<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Tests\TestCase;

class BranchManagerFailClosedTest extends TestCase
{
    public function test_branch_manager_branch_screen_is_limited_to_assigned_branch_and_hides_global_controls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'BRUI-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'My Assigned Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'code' => 'BRUI-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Another Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permissionId = DB::table('permissions')->where('slug', 'branches.manage')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($manager)->get('/admin/branches')
            ->assertOk()
            ->assertSee('My Assigned Branch')
            ->assertDontSee('Another Branch')
            ->assertDontSee('Add Branch')
            ->assertDontSee('Deactivate')
            ->assertDontSee('Delete');
    }

    public function test_unassigned_branch_manager_is_denied_teacher_guardian_and_hr_reads_and_writes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'teacher-management',
            'student-management',
            'guardian-management',
            'hr-staff-management',
        ]);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['teachers.manage', 'guardians.manage', 'hr.manage'])
            ->pluck('id');
        $this->assertCount(3, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)
            ->get('/admin/teachers')
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/teachers', [])
            ->assertForbidden();

        $this->actingAs($manager)
            ->get('/admin/guardians')
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/guardians', [])
            ->assertForbidden();

        $this->actingAs($manager)
            ->get('/admin/hr')
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/hr/staff', [])
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/admin/hr/payrolls', [])
            ->assertForbidden();
    }
    public function test_unassigned_branch_manager_cannot_access_exam_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'exam-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')->where('slug', 'exams.manage')->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->get('/admin/exams')->assertForbidden();
        $this->actingAs($manager)->post('/admin/exams', [
            'title' => 'Unassigned Branch Exam',
            'duration_minutes' => 30,
            'pass_marks' => 10,
        ])->assertForbidden();

        $this->assertDatabaseMissing('exams', ['title' => 'Unassigned Branch Exam']);
    }


    public function test_unassigned_branch_manager_cannot_view_or_change_course_enrollments(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')->where('slug', 'enrollments.manage')->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'BRANCH-ENROLL-01',
            'title' => 'Branch Enrollment Isolation',
            'slug' => 'branch-enrollment-isolation',
            'price' => 0,
            'status' => 'published',
        ]);
        $enrollment = Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'branch_id' => null,
            'status' => 'pending',
        ]);

        $this->actingAs($manager)->get('/admin/enrollments')->assertForbidden();
        $this->actingAs($manager)->patch('/admin/enrollments/' . $enrollment->id . '/status', [
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'status' => 'pending']);
    }

}
