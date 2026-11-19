<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Models\\Role;
use Tests\\TestCase;

class StudentManagementProvisioningSecurityTest extends TestCase
{
    public function test_student_account_is_not_created_when_canonical_student_role_is_missing(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        Role::query()->where('slug', 'student')->delete();

        $this->actingAs($admin)
            ->post('/admin/students', [
                'name' => 'Role Missing Learner',
                'email' => 'role.missing@aec.test',
                'password' => 'StrongPassword123!',
                'student_code' => 'ROLE-MISSING-001',
                'status' => 'active',
            ])
            ->assertStatus(503);

        $this->assertDatabaseMissing('users', ['email' => 'role.missing@aec.test']);
        $this->assertDatabaseMissing('students', ['student_code' => 'ROLE-MISSING-001']);
    }
}
