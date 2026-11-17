<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PluginRecord;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Tests\TestCase;

class ThemeAppearanceTest extends TestCase
{
    public function test_admin_can_customize_global_lms_core_theme_without_code(): void
    {
        $this->markSystemInstalled();
        $admin = User::query()->create([
            'name' => 'Theme Admin',
            'email' => 'theme-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->create(['slug' => 'admin', 'name' => 'Administrator', 'is_system' => true]);
        $permission = Permission::query()->create([
            'slug' => 'core.settings.manage',
            'name' => 'Manage Core System Settings',
            'module' => 'core',
        ]);
        $role->permissions()->sync([$permission->id]);
        $admin->roles()->attach($role);

        $this->actingAs($admin)->get(route('admin.appearance.index'))
            ->assertOk()
            ->assertSee('Global Theme Palette')
            ->assertSee('LMS Core + All Plugins');

        $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'scope' => 'global',
            'colors' => [
                'primary' => '#123456',
                'primary_hover' => '#234567',
                'secondary' => '#345678',
                'accent' => '#456789',
                'page_bg' => '#F0F0F0',
                'surface' => '#FFFFFF',
                'surface_alt' => '#FAFAFA',
                'text' => '#101010',
                'muted' => '#666666',
                'border' => '#DDDDDD',
                'sidebar_bg' => '#111111',
                'sidebar_text' => '#EEEEEE',
                'header_bg' => '#FFFFFF',
                'success' => '#008800',
                'warning' => '#AA7700',
                'danger' => '#CC0000',
                'info' => '#0066AA',
                'link' => '#0033CC',
                'button_text' => '#FFFFFF',
                'input_bg' => '#FFFFFF',
                'input_text' => '#111111',
            ],
        ])->assertRedirect(route('admin.appearance.index', ['scope' => 'global']));

        $saved = SystemSetting::query()->where('key', 'appearance.theme.global')->firstOrFail();
        $this->assertSame('#123456', json_decode((string) $saved->value, true)['primary']);
        $this->assertSame($admin->id, $saved->updated_by);

        $this->actingAs($admin)->post(route('admin.appearance.reset'), ['scope' => 'global'])
            ->assertRedirect(route('admin.appearance.index', ['scope' => 'global']));
        $this->assertDatabaseMissing('system_settings', ['key' => 'appearance.theme.global']);
    }

    public function test_admin_can_set_a_plugin_only_theme_that_does_not_overwrite_global_palette(): void
    {
        $this->markSystemInstalled();
        $admin = User::query()->create([
            'name' => 'Theme Admin',
            'email' => 'plugin-theme-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->create(['slug' => 'admin', 'name' => 'Administrator', 'is_system' => true]);
        $permission = Permission::query()->create([
            'slug' => 'core.settings.manage',
            'name' => 'Manage Core System Settings',
            'module' => 'core',
        ]);
        $role->permissions()->sync([$permission->id]);
        $admin->roles()->attach($role);
        PluginRecord::query()->create([
            'slug' => 'student-management',
            'name' => 'Student Management',
            'version' => '1.0.0',
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'scope' => 'plugin.student-management',
            'colors' => ['primary' => '#ABCDEF', 'text' => '#123123'],
        ])->assertRedirect(route('admin.appearance.index', ['scope' => 'plugin.student-management']));

        $this->assertDatabaseHas('system_settings', ['key' => 'appearance.theme.plugin.student-management']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'appearance.theme.global']);
    }

    public function test_appearance_settings_are_permission_protected(): void
    {
        $this->markSystemInstalled();
        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'theme-teacher@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($teacher)->get(route('admin.appearance.index'))->assertForbidden();
    }

    public function test_invalid_color_values_are_rejected(): void
    {
        $this->markSystemInstalled();
        $admin = User::query()->create([
            'name' => 'Theme Admin',
            'email' => 'invalid-theme-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->create(['slug' => 'admin', 'name' => 'Administrator', 'is_system' => true]);
        $permission = Permission::query()->create([
            'slug' => 'core.settings.manage',
            'name' => 'Manage Core System Settings',
            'module' => 'core',
        ]);
        $role->permissions()->sync([$permission->id]);
        $admin->roles()->attach($role);

        $this->actingAs($admin)->from(route('admin.appearance.index'))->post(route('admin.appearance.update'), [
            'scope' => 'global',
            'colors' => ['primary' => 'red; background:url(javascript:alert(1))'],
        ])->assertSessionHasErrors('colors.primary');

        $this->assertDatabaseMissing('system_settings', ['key' => 'appearance.theme.global']);
    }
}
