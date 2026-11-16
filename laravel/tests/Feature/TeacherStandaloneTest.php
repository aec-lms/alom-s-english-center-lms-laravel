<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
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
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teachers', [
            'employee_code' => 'TEACHER-GLOBAL-001',
            'branch_id' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'global.teacher@aec.test',
            'branch_id' => null,
        ]);
    }
}
