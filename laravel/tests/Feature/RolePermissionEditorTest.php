<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
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
}
