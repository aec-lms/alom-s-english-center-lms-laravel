<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Tests\TestCase;

class StudentStandaloneTest extends TestCase
{
    public function test_student_management_can_create_global_student_without_branch_plugin(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->assertFalse(app(PluginManager::class)->isInstalled('branch-management'));

        $this->actingAs($admin)
            ->get('/admin/students')
            ->assertOk();

        $this->actingAs($admin)
            ->post('/admin/students', [
                'name' => 'Global Learner',
                'email' => 'global.learner@aec.test',
                'phone' => '01712345678',
                'password' => 'StrongPassword123!',
                'student_code' => 'GLOBAL-001',
                'status' => 'active',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', [
            'student_code' => 'GLOBAL-001',
            'branch_id' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'global.learner@aec.test',
            'branch_id' => null,
        ]);
    }
}
