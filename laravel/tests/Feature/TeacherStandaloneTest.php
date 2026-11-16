<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Tests\TestCase;

class TeacherStandaloneTest extends TestCase
{
    public function test_teacher_management_can_create_global_teacher_without_branch_plugin(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->assertFalse(app(PluginManager::class)->isInstalled('branch-management'));
        $this->actingAs($admin)->get('/admin/teachers')->assertOk();

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Global Teacher',
            'email' => 'global.teacher@aec.test',
            'employee_code' => 'TEACHER-GLOBAL-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teachers', [
            'employee_code' => 'TEACHER-GLOBAL-001',
            'branch_id' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'global.teacher@aec.test',
            'branch_id' => null,
        ]);
    }

    public function test_deleting_teacher_moves_it_to_recycle_bin_and_admin_can_restore_it(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Retained Instructor',
            'email' => 'retained.instructor@aec.test',
            'employee_code' => 'SAFE-TEACH-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'SAFE-TEACH-001')->firstOrFail();
        $this->actingAs($admin)->delete('/admin/teachers/' . $teacher->id)->assertRedirect();

        $this->assertTrue(Teacher::withTrashed()->findOrFail($teacher->id)->trashed());
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/recycle-bin')->assertOk()->assertSee('SAFE-TEACH-001');
        $this->actingAs($admin)
            ->post('/admin/recycle-bin/teachers/' . $teacher->id . '/restore')
            ->assertRedirect('/admin/recycle-bin')
            ->assertSessionHasNoErrors();

        $this->assertFalse(Teacher::query()->findOrFail($teacher->id)->trashed());
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'employee_code' => 'SAFE-TEACH-001']);
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'is_active' => true]);
    }

}
