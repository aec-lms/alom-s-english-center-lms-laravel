<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Tests\TestCase;

class AttendanceStandaloneIndexTest extends TestCase
{
    public function test_attendance_plugin_index_renders_without_course_or_student_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['attendance-management']);

        $admin = $this->createUserWithRole('admin');
        $permission = Permission::query()->where('slug', 'attendance.manage')->firstOrFail();
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($admin)->get('/admin/attendance')
            ->assertOk()
            ->assertSee('Attendance & Progress Tracking')
            ->assertSee('Attendance Management is installed independently.')
            ->assertSee('Course Management')
            ->assertSee('Student Management')
            ->assertSee('No students available for this branch.')
            ->assertSee('No attendance marked yet.');
    }

    public function test_attendance_index_uses_empty_integrations_when_sibling_records_are_absent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['attendance-management']);

        $admin = $this->createUserWithRole('admin');
        $permission = Permission::query()->where('slug', 'attendance.manage')->firstOrFail();
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $response = $this->actingAs($admin)->get('/admin/attendance')->assertOk();
        $response->assertDontSee('Select batch</option>');
        $response->assertSee('disabled:cursor-not-allowed', false);
    }
}
