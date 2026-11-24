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
            ->assertSee('System Information')
            ->assertSee('aec-edupro-dashboard', false)
            ->assertSee('aec-admin-dashboard-page', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('aec-dashboard-welcome', false)
            ->assertSee('aec-dashboard-welcome__mission', false)
            ->assertSee('aec-dashboard-kpi--users', false)
            ->assertSee('body.aec-admin-dashboard-page #admin-sidebar', false)
            ->assertDontSee('body:has(#dashboard-widgets)', false)
            ->assertSee('Manage Users')
            ->assertSee('Welcome Back')
            ->assertSee('Total Students')
            ->assertSee('Total Teachers')
            ->assertSee('Total Courses')
            ->assertSee('Total Batches')
            ->assertSee('Analytics Overview')
            ->assertSee('Monthly dashboard analytics for students, courses and batches')
            ->assertSee('A detailed data table follows for assistive technology.');
            ->assertSee('Recent Activities')
            ->assertSee('Quick Actions')
            ->assertSee('Module Dashboards')
            ->assertSee('data-live-metric="students"', false)
            ->assertSee('data-live-metric="teachers"', false)
            ->assertSee('data-live-metric="courses"', false)
            ->assertSee('data-live-metric="batches"', false)
            ->assertSee('aec-dashboard-panel', false)
            ->assertSee('data-refresh-url=', false)
            ->assertDontSee('<div id="dashboard-widgets"<div', false);

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
            // Assert rendered widget elements, not CSS selectors that may style hidden widgets.
            ->assertDontSee('<section data-dashboard-widget="audit_trail"', false)
            ->assertDontSee('<section data-dashboard-widget="backups"', false);
    }

    public function test_admin_dashboard_visual_skin_is_not_applied_to_other_admin_pages(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-scope-admin@aec.test');

        $this->actingAs($admin)->get(route('admin.plugins.index'))
            ->assertOk()
            ->assertDontSee('aec-admin-dashboard-page', false)
            ->assertDontSee('aec-dashboard-welcome', false);
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

    public function test_admin_can_reorder_dashboard_widgets_and_order_is_preserved_on_render(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-order@aec.test');

        $this->actingAs($admin)->post(route('admin.dashboard.widgets'), [
            'widgets' => ['audit_trail', 'system_info', 'users'],
        ])->assertRedirect(route('admin.dashboard'));

        $stored = SystemSetting::query()
            ->where('key', 'admin.dashboard.widgets.user.' . $admin->id)
            ->value('value');
        $this->assertSame(['audit_trail', 'system_info', 'users'], json_decode($stored, true));

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $html = $response->getContent();
        $auditPosition = strpos($html, '<section data-dashboard-widget="audit_trail"');
        $systemPosition = strpos($html, '<section data-dashboard-widget="system_info"');
        $usersPosition = strpos($html, '<section data-dashboard-widget="users"');
        $this->assertNotFalse($auditPosition);
        $this->assertNotFalse($systemPosition);
        $this->assertNotFalse($usersPosition);
        $this->assertTrue($auditPosition < $systemPosition && $systemPosition < $usersPosition);
    }


    public function test_admin_layout_includes_accessible_animated_progress_styles(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'animated-progress-admin@aec.test');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('@keyframes aec-progress-fill-enter', false)
            ->assertSee('prefers-reduced-motion: reduce', false);
    }


    public function test_dashboard_refresh_returns_private_live_metrics_for_authorized_admin(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-refresh@aec.test');

        $this->actingAs($admin)->getJson(route('admin.dashboard.refresh'))
            ->assertOk()
            ->assertJsonStructure(['metrics' => ['users', 'students', 'teachers', 'courses', 'batches', 'plugins_total', 'plugins_enabled', 'backups'], 'refreshed_at'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_dashboard_widget_analytics_are_tracked_per_user_and_reject_unknown_widgets(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-analytics@aec.test');

        $this->actingAs($admin)->postJson(route('admin.dashboard.widget-analytics'), ['widget' => 'users'])
            ->assertStatus(202)->assertJson(['tracked' => true]);
        $this->assertSame(['users' => 1], json_decode(
            SystemSetting::query()->where('key', 'admin.dashboard.analytics.user.' . $admin->id)->value('value'),
            true
        ));

        $this->actingAs($admin)->postJson(route('admin.dashboard.widget-analytics'), ['widget' => 'arbitrary'])
            ->assertUnprocessable();
    }

    public function test_admin_can_save_validated_widget_sizes_with_personal_layout(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-sizes@aec.test');

        $this->actingAs($admin)->post(route('admin.dashboard.widgets'), [
            'widgets' => ['users', 'system_info'],
            'sizes' => ['users' => 'small', 'system_info' => 'large'],
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertSame(
            ['users' => 'small', 'system_info' => 'large'],
            json_decode(SystemSetting::query()->where('key', 'admin.dashboard.widget_sizes.user.' . $admin->id)->value('value'), true)
        );
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('data-size="small"', false)
            ->assertSee('data-size="large"', false);
    }



    public function test_dashboard_customizer_exposes_presets_and_widget_size_controls(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'dashboard-presets@aec.test');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Executive overview')
            ->assertSee('Operations &amp; security', false)
            ->assertSee('Teaching &amp; learning', false)
            ->assertSee('Finance &amp; records', false)
            ->assertSee('name="sizes[users]"', false)
            ->assertSee('data-analytics-url', false)
            ->assertSee('data-refresh-url', false);
    }


}
