<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use Tests\TestCase;

class DashboardCustomizationTest extends TestCase
{
    public function test_admin_can_save_a_personal_dashboard_widget_layout(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-admin@aec.test');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Customize Dashboard')
            ->assertSee('System Information');

        $this->actingAs($admin)->post(route('admin.dashboard.widgets'), [
            'widgets' => ['users', 'system_info'],
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseHas('system_settings', [
            'key' => 'admin.dashboard.widgets.user.' . $admin->id,
            'value' => json_encode(['users', 'system_info']),
            'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.dashboard.widgets.updated']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Registered Accounts')
            ->assertSee('System Information')
            ->assertDontSee('Recent Security Audit Trail')
            ->assertDontSee('Encrypted Backups');
    }

    public function test_dashboard_widget_preferences_are_isolated_per_admin(): void
    {
        $this->markSystemInstalled();
        $first = $this->createUserWithRole('admin', null, 'dashboard-first@aec.test');
        $second = $this->createUserWithRole('admin', null, 'dashboard-second@aec.test');

        $this->actingAs($first)->post(route('admin.dashboard.widgets'), ['widgets' => ['users']])->assertRedirect();
        $this->actingAs($second)->get(route('admin.dashboard'))->assertSee('Recent Security Audit Trail');
        $this->assertDatabaseHas('system_settings', ['key' => 'admin.dashboard.widgets.user.' . $first->id]);
        $this->assertDatabaseMissing('system_settings', ['key' => 'admin.dashboard.widgets.user.' . $second->id]);
    }

    public function test_unknown_dashboard_widgets_are_rejected(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-validation@aec.test');

        $this->actingAs($admin)->from(route('admin.dashboard'))
            ->post(route('admin.dashboard.widgets'), ['widgets' => ['users', 'run-arbitrary-code']])
            ->assertSessionHasErrors('widgets.1');

        $this->assertDatabaseMissing('system_settings', ['key' => 'admin.dashboard.widgets.user.' . $admin->id]);
    }

    public function test_dashboard_customization_requires_dashboard_permission(): void
    {
        $this->markSystemInstalled();
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->post(route('admin.dashboard.widgets'), ['widgets' => ['users']])
            ->assertForbidden();
    }
}
