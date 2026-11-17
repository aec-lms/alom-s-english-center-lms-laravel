<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\LiveClass;
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
    public function test_unassigned_branch_manager_cannot_delete_global_live_class_or_manage_global_categories(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')->where('slug', 'courses.manage')->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $course = Course::query()->create([
            'code' => 'BRANCH-LIVE-DELETE',
            'title' => 'Global Course Record',
            'slug' => 'global-course-record',
            'price' => 0,
            'status' => 'published',
            'branch_id' => null,
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Global Live Class',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now(),
            'status' => 'upcoming',
            'capacity' => 300,
        ]);
        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => 'Global Category',
            'slug' => 'global-category',
            'description' => null,
            'color' => 'blue',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->delete('/admin/courses/live-classes/' . $liveClass->id)->assertForbidden();
        $this->actingAs($manager)->post('/admin/courses/categories', [
            'name' => 'Unauthorized Category',
        ])->assertForbidden();
        $this->actingAs($manager)->delete('/admin/courses/categories/' . $categoryId)->assertForbidden();

        $this->assertDatabaseHas('live_classes', ['id' => $liveClass->id]);
        $this->assertDatabaseHas('course_categories', ['id' => $categoryId, 'name' => 'Global Category']);
    }

}
