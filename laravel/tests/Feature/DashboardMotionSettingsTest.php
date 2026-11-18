<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Models\\SystemSetting;
use Tests\\TestCase;

class DashboardMotionSettingsTest extends TestCase
{
    public function test_admin_can_save_global_dashboard_motion_preferences(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)->post(route('admin.dashboard.motion'), [
            'enabled' => '1',
            'preset' => 'dynamic',
            'speed' => 'fast',
            'intensity' => 'high',
            'loading' => 'shimmer',
            'counters' => '1',
            'hover' => '1',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $stored = json_decode((string) SystemSetting::query()->where('key', 'dashboard.motion.global')->value('value'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'enabled' => true,
            'preset' => 'dynamic',
            'speed' => 'fast',
            'intensity' => 'high',
            'loading' => 'shimmer',
            'counters' => true,
            'hover' => true,
        ], $stored);
    }

    public function test_admin_dashboard_renders_motion_controls_and_accessible_reduced_motion_override(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Motion &amp; Loading', false)
            ->assertSee('data-motion-enabled="true"', false)
            ->assertSee('prefers-reduced-motion: reduce', false)
            ->assertSee('Animate statistic counters');
    }

    public function test_branch_manager_cannot_change_global_dashboard_motion_settings(): void
    {
        $this->markSystemInstalled();
        $manager = $this->createUserWithRole('branch_manager');

        $this->actingAs($manager)
            ->post(route('admin.dashboard.motion'), [
                'enabled' => '1',
                'preset' => 'premium',
                'speed' => 'normal',
                'intensity' => 'medium',
                'loading' => 'minimal',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('system_settings', ['key' => 'dashboard.motion.global']);
    }

    public function test_invalid_motion_preferences_are_rejected(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.dashboard.motion'), [
                'preset' => 'flash-everything',
                'speed' => 'instant',
                'intensity' => 'extreme',
                'loading' => 'remote-script',
            ])
            ->assertSessionHasErrors(['preset', 'speed', 'intensity', 'loading']);

        $this->assertDatabaseMissing('system_settings', ['key' => 'dashboard.motion.global']);
    }
}
