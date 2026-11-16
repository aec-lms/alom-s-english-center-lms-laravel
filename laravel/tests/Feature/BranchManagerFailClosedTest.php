<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Tests\\TestCase;

class BranchManagerFailClosedTest extends TestCase
{
    public function test_unassigned_branch_manager_is_denied_teacher_guardian_and_hr_reads_and_writes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'teacher-management',
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
}
