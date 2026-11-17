<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentTeacherBranchOptionsTest extends TestCase
{
    public function test_branch_manager_only_sees_assigned_branch_in_student_and_teacher_forms(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'teacher-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'FORM-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Assigned Form Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'code' => 'FORM-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Other Form Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['students.manage', 'teachers.manage'])
            ->pluck('id');
        $this->assertCount(2, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->get('/admin/students')
            ->assertOk()
            ->assertSee('Assigned Form Branch')
            ->assertDontSee('Other Form Branch');
        $this->actingAs($manager)->get('/admin/teachers')
            ->assertOk()
            ->assertSee('Assigned Form Branch')
            ->assertDontSee('Other Form Branch');
    }
}
