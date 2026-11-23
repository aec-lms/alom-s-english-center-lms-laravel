<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\HrStaffManagement\Models\StaffAttendanceRecord;
use Plugins\Modules\HrStaffManagement\Models\StaffProfile;
use Tests\TestCase;

final class StaffAttendanceRegisterTest extends TestCase
{
    public function test_staff_attendance_is_upserted_once_per_day_and_branch_managers_cannot_cross_branches(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'hr-staff-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'STA-A', 'name' => 'Staff Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'STA-B', 'name' => 'Staff Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permission = \App\Models\Permission::query()->where('slug', 'hr.manage')->firstOrFail();
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $own = StaffProfile::query()->create([
            'branch_id' => $branchA, 'employee_code' => 'STA-A-001',
            'full_name' => 'Staff A', 'designation' => 'Office Assistant',
            'department' => 'Operations', 'base_salary' => 18000, 'status' => 'active',
        ]);
        $foreign = StaffProfile::query()->create([
            'branch_id' => $branchB, 'employee_code' => 'STA-B-001',
            'full_name' => 'Staff B', 'designation' => 'Office Assistant',
            'department' => 'Operations', 'base_salary' => 18000, 'status' => 'active',
        ]);

        $payload = [
            'staff_profile_id' => $own->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'check_in_at' => now()->startOfDay()->addHours(9)->format('Y-m-d H:i:s'),
            'check_out_at' => now()->startOfDay()->addHours(17)->format('Y-m-d H:i:s'),
        ];
        $this->actingAs($manager)->post('/admin/hr/attendance', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('staff_attendance_records', [
            'staff_profile_id' => $own->id, 'branch_id' => $branchA,
            'attendance_date' => now()->toDateString(), 'status' => 'present',
        ]);

        $payload['status'] = 'late';
        $this->actingAs($manager)->post('/admin/hr/attendance', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, StaffAttendanceRecord::query()->where('staff_profile_id', $own->id)->whereDate('attendance_date', now()->toDateString())->count());
        $this->assertSame('late', StaffAttendanceRecord::query()->where('staff_profile_id', $own->id)->firstOrFail()->status);

        $this->actingAs($manager)->post('/admin/hr/attendance', [
            ...$payload, 'staff_profile_id' => $foreign->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('staff_attendance_records', ['staff_profile_id' => $foreign->id]);
    }
}
