<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceAuditHistoryTest extends TestCase
{
    private function createAuditEntry(int $branchId, string $studentName, string $code, int $sourceId): void
    {
        $studentUser = $this->createUserWithRole('student', $branchId, strtolower($code) . '@example.test');
        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => $code,
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mappingId = DB::table('attendance_batch_mappings')->insertGetId([
            'source_type' => 'course_batch',
            'source_id' => $sourceId,
            'branch_id' => $branchId,
            'display_name' => $studentName . ' Batch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entryId = DB::table('attendance_entries')->insertGetId([
            'attendance_batch_mapping_id' => $mappingId,
            'student_id' => $studentId,
            'branch_id' => $branchId,
            'attendance_date' => now()->toDateString(),
            'status' => 'late',
            'remarks' => null,
            'marked_by' => $studentUser->id,
            'sync_uuid' => null,
            'source' => 'offline',
            'edited_reason' => 'Confirmed from paper register',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_entry_audit_logs')->insert([
            'attendance_entry_id' => $entryId,
            'actor_id' => $studentUser->id,
            'event' => 'updated',
            'old_status' => 'present',
            'new_status' => 'late',
            'reason' => 'Confirmed from paper register',
            'source' => 'offline',
            'occurred_at' => now(),
        ]);
    }

    public function test_admin_can_filter_view_and_export_attendance_audit_history(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'attendance-management']);
        $admin = $this->createUserWithRole('admin');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'AUD-DHK', 'name' => 'Audit Dhaka', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createAuditEntry($branchId, 'Audit Student', 'AUD-STU-001', 91001);

        $this->actingAs($admin)->get(route('plugins.attendance.audit', ['source' => 'offline']))
            ->assertOk()
            ->assertSee('Attendance Audit History')
            ->assertSee('AUD-STU-001')
            ->assertSee('Confirmed from paper register');

        $this->actingAs($admin)->get(route('plugins.attendance.audit.export', ['source' => 'offline']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertDownload();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'attendance.audit.exported',
            'entity_type' => 'attendance_audit',
        ]);
    }

    public function test_branch_manager_audit_history_is_confined_to_the_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'attendance-management']);
        $localBranch = DB::table('branches')->insertGetId([
            'code' => 'AUD-LOCAL', 'name' => 'Audit Local', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $remoteBranch = DB::table('branches')->insertGetId([
            'code' => 'AUD-REMOTE', 'name' => 'Audit Remote', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('slug', 'branch_manager')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'attendance.manage')->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        $manager = $this->createUserWithRole('branch_manager', $localBranch);

        $this->createAuditEntry($localBranch, 'Local Audit Student', 'AUD-LOCAL-001', 92001);
        $this->createAuditEntry($remoteBranch, 'Remote Audit Student', 'AUD-REMOTE-001', 92002);

        $this->actingAs($manager)->get(route('plugins.attendance.audit'))
            ->assertOk()
            ->assertSee('AUD-LOCAL-001')
            ->assertDontSee('AUD-REMOTE-001')
            ->assertDontSee('Audit Remote Batch');

        $this->actingAs($manager)->get(route('plugins.attendance.audit.export'))
            ->assertOk()
            ->assertDownload();
    }

    public function test_student_cannot_view_admin_attendance_audit_history(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['attendance-management']);
        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->get('/admin/attendance/audit')->assertForbidden();
    }
}
