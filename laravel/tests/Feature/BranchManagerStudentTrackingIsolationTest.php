<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;

class BranchManagerStudentTrackingIsolationTest extends TestCase
{
    public function test_branch_manager_cannot_view_or_filter_another_branch_student_activity(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-tracking']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'ST-ISO-A',
            'name' => 'Student Tracking Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'ST-ISO-B',
            'name' => 'Student Tracking Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', (int) $branchA);
        $permissionId = DB::table('permissions')->where('slug', 'student-tracking.view')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $studentA = $this->createUserWithRole('student', (int) $branchA);
        $studentB = $this->createUserWithRole('student', (int) $branchB);
        app(StudentActivityTracker::class)->record((int) $studentA->id, 'lesson_completed', 'lesson', 'branch-a-lesson', 120, [
            'course_title' => 'Branch A Activity Visible',
        ]);
        app(StudentActivityTracker::class)->record((int) $studentB->id, 'lesson_completed', 'lesson', 'branch-b-lesson', 120, [
            'course_title' => 'Branch B Activity Private',
        ]);

        $this->actingAs($manager)->get('/admin/student-tracking')
            ->assertOk()
            ->assertSee('Branch A Activity Visible')
            ->assertDontSee('Branch B Activity Private');

        $this->actingAs($manager)->get('/admin/student-tracking?student_id=' . $studentB->id)
            ->assertOk()
            ->assertDontSee('Branch B Activity Private');

        DB::table('branches')->where('id', $branchA)->update(['is_active' => false]);
        $this->actingAs($manager)->get('/admin/student-tracking')->assertForbidden();
    }
}
