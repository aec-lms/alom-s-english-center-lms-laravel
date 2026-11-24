<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPluginWidgetsTest extends TestCase
{
    public function test_enabled_plugin_registers_a_permission_scoped_customizable_dashboard_widget(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'teacher-management', 'guardian-management', 'branch-management']);
        $admin = $this->createUserWithRole('admin');
        $permissionIds = DB::table('permissions')->whereIn('slug', [
            'students.manage', 'teachers.manage', 'guardians.manage', 'branches.manage',
        ])->pluck('id');
        $this->assertCount(4, $permissionIds);
        $admin->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Student Management')
            ->assertSee('Teacher Management')
            ->assertSee('Guardian Management')
            ->assertSee('Branch Management')
            ->assertSee('data-default-enabled="false"', false)
            ->assertDontSee('data-dashboard-widget="student-management.overview"', false);

        $this->actingAs($admin)->post('/admin/dashboard/widgets', [
            'widgets' => [
                'users',
                'student-management.overview',
                'teacher-management.overview',
                'guardian-management.overview',
                'branch-management.overview',
            ],
        ])->assertRedirect('/admin');

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('data-dashboard-widget="student-management.overview"', false)
            ->assertSee('data-dashboard-widget="teacher-management.overview"', false)
            ->assertSee('data-dashboard-widget="guardian-management.overview"', false)
            ->assertSee('data-dashboard-widget="branch-management.overview"', false)
            ->assertSee('Open Students')
            ->assertSee(route('plugins.students.index'))
            ->assertSee('Open Teachers')
            ->assertSee(route('plugins.teachers.index'))
            ->assertSee('Open Guardians')
            ->assertSee(route('plugins.guardians.index'))
            ->assertSee('Open Branches')
            ->assertSee(route('plugins.branches.index'));
    }

    public function test_plugin_dashboard_widget_is_not_discoverable_without_its_permission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $viewer = $this->createUserWithRole('teacher');
        $dashboardPermissionId = DB::table('permissions')->where('slug', 'core.dashboard.view')->value('id');
        $studentPermissionId = DB::table('permissions')->where('slug', 'students.manage')->value('id');
        $this->assertNotNull($dashboardPermissionId);
        $this->assertNotNull($studentPermissionId);
        $viewer->roles()->first()->permissions()->syncWithoutDetaching([$dashboardPermissionId]);
        $viewer->roles()->first()->permissions()->detach([$studentPermissionId]);

        $this->actingAs($viewer)->get('/admin')
            ->assertOk()
            ->assertDontSee('value="student-management.overview"', false)
            ->assertDontSee('data-dashboard-widget="student-management.overview"', false);

        $this->actingAs($viewer)->from('/admin')->post('/admin/dashboard/widgets', [
            'widgets' => ['student-management.overview'],
        ])->assertSessionHasErrors('widgets.0');
    }

    public function test_academic_modules_register_live_progress_and_exam_dashboard_widgets(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-tracking', 'exam-management']);
        $admin = $this->createUserWithRole('admin');
        $permissionIds = DB::table('permissions')->whereIn('slug', [
            'courses.manage', 'student-tracking.view', 'exams.manage',
        ])->pluck('id');
        $this->assertCount(3, $permissionIds);
        $admin->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Live & Upcoming Classes')
            ->assertSee('Course Learning Progress')
            ->assertSee('Exam Performance');

        $this->actingAs($admin)->post('/admin/dashboard/widgets', [
            'widgets' => [
                'course-management.live-classes',
                'student-tracking.course-progress',
                'exam-management.exam-performance',
            ],
        ])->assertRedirect('/admin');

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('data-dashboard-widget="course-management.live-classes"', false)
            ->assertSee('data-dashboard-widget="student-tracking.course-progress"', false)
            ->assertSee('data-dashboard-widget="exam-management.exam-performance"', false)
            ->assertSee('No live or upcoming classes are scheduled.')
            ->assertSee('Progress will appear when published courses have enrollments')
            ->assertSee('No submitted exam attempts have been recorded');
    }

    public function test_branch_manager_never_receives_global_plugin_dashboard_widgets(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'WIDGET-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Widget Scope Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $permissionIds = DB::table('permissions')->whereIn('slug', ['core.dashboard.view', 'branches.manage', 'students.manage'])->pluck('id');
        $this->assertCount(3, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->get('/admin')
            ->assertOk()
            ->assertDontSee('Student Management')
            ->assertDontSee('Plugin Overview')
            ->assertSee('Registered Accounts');
    }
}
