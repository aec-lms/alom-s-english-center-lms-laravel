<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class InstitutionSettingsTest extends TestCase
{
    private function settingsAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Settings Admin',
            'email' => 'institution-settings-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator', 'is_system' => true]);
        $permission = Permission::query()->firstOrCreate(['slug' => 'core.settings.manage'], [
            'name' => 'Manage Core System Settings',
            'module' => 'core',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->attach($role);

        return $admin;
    }

    public function test_admin_can_update_institution_and_regional_defaults_without_code(): void
    {
        $this->markSystemInstalled();
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->get(route('admin.institution-settings.edit'))
            ->assertOk()
            ->assertSee('Institution &amp; Regional Settings');

        $this->actingAs($admin)->post(route('admin.institution-settings.update'), [
            'name' => 'Example Academy',
            'website' => 'https://academy.example',
            'country_code' => 'GB',
            'locale' => 'en-GB',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
        ])->assertRedirect(route('admin.institution-settings.edit'));

        $this->assertDatabaseHas('system_settings', ['key' => 'institution.name', 'value' => 'Example Academy']);
        $this->assertDatabaseHas('system_settings', ['key' => 'institution.country_code', 'value' => 'GB']);
        $this->assertDatabaseHas('system_settings', ['key' => 'localization.locale', 'value' => 'en-GB']);
        $this->assertDatabaseHas('system_settings', ['key' => 'localization.timezone', 'value' => 'Europe/London']);
        $this->assertDatabaseHas('system_settings', ['key' => 'finance.default_currency', 'value' => 'GBP']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'institution.settings.updated']);
        $this->assertSame('Example Academy', config('app.name'));
        $this->assertSame('Europe/London', config('app.timezone'));
        $this->assertSame('GBP', config('lms.default_currency'));
    }

    public function test_invalid_timezone_is_rejected_and_does_not_write_settings(): void
    {
        $this->markSystemInstalled();
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->from(route('admin.institution-settings.edit'))
            ->post(route('admin.institution-settings.update'), [
                'name' => 'Example Academy',
                'website' => 'https://academy.example',
                'country_code' => 'GB',
                'locale' => 'en-GB',
                'timezone' => 'Not/A_Timezone',
                'currency' => 'GBP',
            ])->assertSessionHasErrors('timezone');

        $this->assertDatabaseMissing('system_settings', ['key' => 'institution.name']);
    }

    public function test_institution_settings_require_core_settings_permission(): void
    {
        $this->markSystemInstalled();
        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'institution-settings-teacher@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($teacher)->get(route('admin.institution-settings.edit'))->assertForbidden();
    }
}
