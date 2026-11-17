<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Models\\Permission;
use App\\Models\\Role;
use App\\Models\\User;
use Tests\\TestCase;

class SetupWizardTest extends TestCase
{
    private function setupAdmin(): User
    {
        $user = User::query()->create(['name' => 'Setup Admin', 'email' => 'setup-admin@example.test', 'password' => 'password', 'is_active' => true]);
        $role = Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator', 'is_system' => true]);
        $permission = Permission::query()->firstOrCreate(['slug' => 'core.dashboard.view'], ['name' => 'View Dashboard', 'module' => 'core']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_setup_wizard_saves_progress_and_resumes_between_steps(): void
    {
        $this->markSystemInstalled();
        $admin = $this->setupAdmin();
        $this->actingAs($admin)->get(route('admin.setup.index'))->assertOk()->assertSee('LMS Setup Wizard');
        $this->actingAs($admin)->post(route('admin.setup.save', ['step' => 'institution']), [
            'name' => 'Alom Academy', 'website' => 'https://example.test', 'locale' => 'bn',
            'timezone' => 'Asia/Dhaka', 'currency' => 'BDT',
        ])->assertRedirect(route('admin.setup.index', ['step' => 'branding']));
        $this->assertDatabaseHas('system_settings', ['key' => 'institution.name', 'value' => 'Alom Academy']);
        $this->assertDatabaseHas('system_settings', ['key' => 'setup_wizard.progress']);
        $this->actingAs($admin)->get(route('admin.setup.index', ['step' => 'branding']))->assertOk()->assertSee('Media Library থেকে Logo');
    }

    public function test_academic_year_requires_end_date_after_start_date(): void
    {
        $this->markSystemInstalled();
        $admin = $this->setupAdmin();
        $this->actingAs($admin)->post(route('admin.setup.save', ['step' => 'academic']), [
            'academic_name' => '2026-27', 'starts_at' => '2026-07-01', 'ends_at' => '2026-06-30',
        ])->assertSessionHasErrors('ends_at');
    }

    public function test_setup_wizard_is_permission_protected(): void
    {
        $this->markSystemInstalled();
        $student = User::query()->create(['name' => 'Student', 'email' => 'setup-student@example.test', 'password' => 'password', 'is_active' => true]);
        $this->actingAs($student)->get(route('admin.setup.index'))->assertForbidden();
    }
}
