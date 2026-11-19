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
        $this->activatePlugins(['branch-management', 'student-management', 'student-tracking']);

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

        // Profile branch is authoritative even when the legacy account branch disagrees.
        $studentA = $this->createUserWithRole('student', (int) $branchB);
        $studentB = $this->createUserWithRole('student', (int) $branchA);
        $profileA = DB::table('students')->insertGetId([
            'user_id' => $studentA->id, 'branch_id' => $branchA,
            'student_code' => 'TRACK-A-' . strtoupper(uniqid()), 'admission_date' => now()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'user_id' => $studentB->id, 'branch_id' => $branchB,
            'student_code' => 'TRACK-B-' . strtoupper(uniqid()), 'admission_date' => now()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(StudentActivityTracker::class)->record((int) $studentA->id, 'lesson_completed', 'lesson', 'branch-a-lesson', 120, [
            'course_title' => 'Branch A Activity Visible',
        ]);
        app(StudentActivityTracker::class)->record((int) $studentB->id, 'lesson_completed', 'lesson', 'branch-b-lesson', 120, [
            'course_title' => 'Branch B Activity Private',
        ]);

        $this->assertDatabaseHas('student_activity_events', ['user_id' => $studentA->id, 'student_id' => $profileA, 'branch_id' => $branchA]);

        $this->actingAs($manager)->get('/admin/student-tracking')
            ->assertOk()
            ->assertSee('Student Learning Activity')->assertSee('Branch A Activity Visible')
            ->assertDontSee('Branch B Activity Private');

        $this->actingAs($manager)->get('/admin/student-tracking?student_id=' . $studentB->id)
            ->assertOk()
            ->assertDontSee('Branch B Activity Private');

        DB::table('branches')->where('id', $branchA)->update(['is_active' => false]);
        $this->actingAs($manager)->get('/admin/student-tracking')->assertForbidden();
    }
}
