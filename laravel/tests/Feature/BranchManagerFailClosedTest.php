<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Tests\TestCase;

class BranchManagerFailClosedTest extends TestCase
{
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


    public function test_unassigned_branch_manager_cannot_manage_courses_with_null_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')->where('slug', 'courses.manage')->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $course = Course::query()->create([
            'code' => 'BRANCH-COURSE-01',
            'title' => 'Unassigned Course',
            'slug' => 'unassigned-course',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $this->actingAs($manager)->get('/admin/courses')->assertForbidden();
        $this->actingAs($manager)->post('/admin/courses', [])->assertForbidden();
        $this->actingAs($manager)->put('/admin/courses/' . $course->id, [])->assertForbidden();
        $this->actingAs($manager)->delete('/admin/courses/' . $course->id)->assertForbidden();

        $this->assertDatabaseHas('courses', ['id' => $course->id, 'title' => 'Unassigned Course']);
    }

}
