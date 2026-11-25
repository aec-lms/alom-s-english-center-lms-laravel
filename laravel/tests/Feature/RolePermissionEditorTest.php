<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

class RolePermissionEditorTest extends TestCase
{
    public function test_admin_can_create_custom_role_and_assign_permissions(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'role-editor@aec.test');
        $permission = Permission::query()->create([
            'slug' => 'reports.custom.view',
            'name' => 'View Custom Reports',
            'module' => 'reports',
        ]);

        $this->actingAs($admin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Roles & Permissions');

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Report Analyst',
            'slug' => 'report_analyst',
            'description' => 'Can view selected reports',
        ])->assertRedirect(route('admin.roles.index'));

        $role = Role::query()->where('slug', 'report_analyst')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.roles.permissions.update', $role), [
            'permissions' => [$permission->id],
        ])->assertRedirect(route('admin.roles.index'));

        $this->assertTrue($role->fresh()->permissions->contains('id', $permission->id));

        $this->actingAs($admin)->put(route('admin.roles.permissions.update', $role), [])
            ->assertRedirect(route('admin.roles.index'));
        $this->assertCount(0, $role->fresh()->permissions);

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.role.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.role.permissions.updated']);
    }

    public function test_protected_admin_role_cannot_have_permissions_reduced(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'role-protected@aec.test');
        $role = Role::query()->where('slug', 'admin')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.roles.permissions.update', $role), [
            'permissions' => [],
        ])->assertForbidden();
    }

    public function test_student_cannot_access_role_permission_editor(): void
    {
        $this->markSystemInstalled();
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($student)->post(route('admin.roles.store'), [
            'name' => 'Intruder',
            'slug' => 'intruder',
        ])->assertForbidden();
    }

    public function test_role_creation_rejects_invalid_or_duplicate_slugs(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'role-validation@aec.test');

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Invalid',
            'slug' => 'Invalid Slug!',
        ])->assertSessionHasErrors('slug');
    }

    public function test_delegated_user_manager_cannot_grant_core_privileges_or_change_system_roles(): void
    {
        $this->markSystemInstalled();
        $managerPermission = Permission::query()->where('slug', 'core.users.manage')->firstOrFail();
        $managerRole = Role::query()->create([
            'name' => 'User Manager',
            'slug' => 'user_manager',
            'description' => 'Can manage ordinary users',
            'is_system' => false,
        ]);
        $managerRole->permissions()->sync([$managerPermission->id]);
        $manager = $this->createUserWithRole('student', null, 'limited-user-manager@aec.test');
        $manager->roles()->sync([$managerRole->id]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $this->actingAs($manager)->post(route('admin.users.store'), [
            'name' => 'Unexpected Administrator',
            'email' => 'unexpected-admin@aec.test',
            'password' => 'StrongPassword!2026',
            'role_id' => $adminRole->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'unexpected-admin@aec.test']);

        $customRole = Role::query()->create([
            'name' => 'Content Operator',
            'slug' => 'content_operator',
            'description' => 'Limited custom role',
            'is_system' => false,
        ]);
        $protectedPermission = Permission::query()->where('slug', 'core.plugins.manage')->firstOrFail();
        $this->actingAs($manager)->put(route('admin.roles.permissions.update', $customRole), [
            'permissions' => [$protectedPermission->id],
        ])->assertForbidden();

        $systemRole = Role::query()->where('slug', 'branch_manager')->firstOrFail();
        $this->actingAs($manager)->put(route('admin.roles.permissions.update', $systemRole), [
            'permissions' => [],
        ])->assertForbidden();
    }

    public function test_role_creation_rolls_back_when_required_audit_write_fails(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced role audit failure');
        });

        try {
            $this->actingAs($admin)->post(route('admin.roles.store'), [
                'name' => 'Audit Protected Role',
                'slug' => 'audit_protected_role',
            ])->assertStatus(500);

            $this->assertDatabaseMissing('roles', ['slug' => 'audit_protected_role']);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_user_creation_rolls_back_when_required_audit_write_fails(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');
        $studentRole = Role::query()->where('slug', 'student')->firstOrFail();

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced user audit failure');
        });

        try {
            $this->actingAs($admin)->post(route('admin.users.store'), [
                'name' => 'Audit Protected User',
                'email' => 'audit-protected-user@aec.test',
                'password' => 'StrongPassword!2026',
                'role_id' => $studentRole->id,
            ])->assertStatus(500);

            $this->assertDatabaseMissing('users', ['email' => 'audit-protected-user@aec.test']);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

}
