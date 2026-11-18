<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\HrStaffManagement\Models\StaffPayroll;
use Plugins\Modules\HrStaffManagement\Models\StaffProfile;
use Tests\TestCase;

final class HrStaffManagementAcceptanceTest extends TestCase
{
    public function test_branch_manager_can_manage_only_own_branch_staff_and_prepare_payroll_for_admin_disbursement(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'hr-staff-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'HR-A',
            'name' => 'HR Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'HR-B',
            'name' => 'HR Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $admin = $this->createUserWithRole('admin');
        $permission = Permission::query()->where('slug', 'hr.manage')->firstOrFail();
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->post('/admin/hr/staff', [
            'employee_code' => 'HR-A-EMP-001',
            'full_name' => 'Branch A Teacher',
            'designation' => 'English Teacher',
            'department' => 'Academic',
            'phone' => '01700000011',
            'email' => 'branch-a-teacher@aec.test',
            'base_salary' => 25000,
            'status' => 'active',
            'branch_id' => $branchB,
        ])->assertSessionHasNoErrors();

        $ownStaff = StaffProfile::query()->where('employee_code', 'HR-A-EMP-001')->firstOrFail();
        $this->assertSame($branchA, (int) $ownStaff->branch_id);

        $foreignStaff = StaffProfile::query()->create([
            'branch_id' => $branchB,
            'employee_code' => 'HR-B-EMP-001',
            'full_name' => 'Branch B Teacher',
            'designation' => 'English Teacher',
            'department' => 'Academic',
            'base_salary' => 30000,
            'status' => 'active',
        ]);

        $this->actingAs($manager)->post('/admin/hr/payrolls', [
            'staff_profile_id' => $foreignStaff->id,
            'payroll_month' => '2026-11',
        ])->assertForbidden();
        $this->assertDatabaseMissing('staff_payrolls', [
            'staff_profile_id' => $foreignStaff->id,
            'payroll_month' => '2026-11',
        ]);

        $this->actingAs($manager)->post('/admin/hr/payrolls', [
            'staff_profile_id' => $ownStaff->id,
            'payroll_month' => '2026-11',
            'bonus' => 1000,
            'deductions' => 500,
        ])->assertSessionHasNoErrors();

        $payroll = StaffPayroll::query()->where('staff_profile_id', $ownStaff->id)->firstOrFail();
        $this->assertSame('pending', $payroll->status);
        $this->assertSame('25500.00', $payroll->net_paid);
        $this->assertSame($branchA, (int) $payroll->branch_id);
        $this->assertNull($payroll->paid_at);
        $this->assertNull($payroll->payment_reference);

        $this->actingAs($manager)->get('/admin/hr')
            ->assertOk()
            ->assertSee('Branch A Teacher')
            ->assertDontSee('Branch B Teacher');

        $this->actingAs($manager)->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid', [
            'payment_reference' => 'BRANCH-MUST-NOT-DISBURSE',
        ])->assertForbidden();
        $this->assertSame('pending', $payroll->fresh()->status);

        $this->actingAs($admin)->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid', [
            'payment_reference' => 'BANK-TRANSFER-HR-001',
        ])->assertSessionHas('status');

        $this->assertSame('paid', $payroll->fresh()->status);
        $this->assertSame('BANK-TRANSFER-HR-001', $payroll->fresh()->payment_reference);
        $this->assertNotNull($payroll->fresh()->paid_at);

        $this->actingAs($admin)->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid', [
            'payment_reference' => 'DUPLICATE-DISBURSEMENT',
        ])->assertStatus(409);
        $this->assertSame('BANK-TRANSFER-HR-001', $payroll->fresh()->payment_reference);
    }
}
