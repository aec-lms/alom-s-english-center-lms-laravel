<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Tests\TestCase;

class CourseBranchIsolationTest extends TestCase
{
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
