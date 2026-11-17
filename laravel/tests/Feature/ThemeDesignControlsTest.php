<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Tests\TestCase;

class ThemeDesignControlsTest extends TestCase
{

    public function test_admin_can_save_global_typography_and_layout_controls(): void
    {
        $this->markSystemInstalled();
        $admin = User::query()->create([
            'name' => 'Design Admin',
            'email' => 'design-admin@example.test',
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

        $this->actingAs($admin)->get(route('admin.appearance.index'))
            ->assertOk()
            ->assertSee('Global Typography &amp; Layout Controls')
            ->assertSee('design[font_family]');

        $this->actingAs($admin)->post(route('admin.appearance.design'), [
            'scope' => 'global',
            'design' => [
                'font_family' => 'noto-sans',
                'font_size' => '17',
                'heading_weight' => '700',
                'content_width' => '1280',
                'border_radius' => '12',
                'density' => 'compact',
            ],
        ])->assertRedirect(route('admin.appearance.index', ['scope' => 'global']));

        $saved = SystemSetting::query()->where('key', 'appearance.design.global')->firstOrFail();
        $this->assertSame([
            'font_family' => 'noto-sans',
            'font_size' => '17',
            'heading_weight' => '700',
            'content_width' => '1280',
            'border_radius' => '12',
            'density' => 'compact',
        ], json_decode((string) $saved->value, true));

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertSee('--aec-font-size:17px', false)
            ->assertSee('--aec-content-width:1280px', false);
    }

    public function test_typography_and_layout_controls_reject_untrusted_css_values(): void
    {
        $this->markSystemInstalled();
        $admin = User::query()->create([
            'name' => 'Design Validation Admin',
            'email' => 'design-validation@example.test',
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

        $this->actingAs($admin)->from(route('admin.appearance.index'))
            ->post(route('admin.appearance.design'), [
                'scope' => 'global',
                'design' => [
                    'font_family' => 'url(javascript:alert(1))',
                    'font_size' => '999',
                    'heading_weight' => '1000',
                    'content_width' => '99999',
                    'border_radius' => '999',
                    'density' => 'compact;position:fixed',
                ],
            ])->assertSessionHasErrors([
                'design.font_family',
                'design.font_size',
                'design.heading_weight',
                'design.content_width',
                'design.border_radius',
                'design.density',
            ]);

        $this->assertDatabaseMissing('system_settings', ['key' => 'appearance.design.global']);
    }

}
