<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchManagerTrackingFailClosedTest extends TestCase
{
    public function test_unassigned_branch_manager_is_denied_attendance_qr_and_student_tracking_reads(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'course-management',
            'attendance-management',
            'student-tracing',
            'student-tracking',
        ]);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['attendance.manage', 'students.trace.view', 'students.trace.export', 'student-tracking.view'])
            ->pluck('id');
        $this->assertCount(4, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->post('/admin/attendance/batches/1/qr', [])->assertForbidden();
        $this->actingAs($manager)->get('/admin/student-tracing')->assertForbidden();
        $this->actingAs($manager)->get('/admin/student-tracing/export')->assertForbidden();
        $this->actingAs($manager)->get('/admin/student-tracking')->assertForbidden();
    }
}
