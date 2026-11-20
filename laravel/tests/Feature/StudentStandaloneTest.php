<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\StudentManagement\Models\Student;
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

    public function test_deleting_student_moves_it_to_recycle_bin_and_restore_keeps_account_inactive(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Retained Learner',
            'email' => 'retained.learner@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'SAFE-RET-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student = Student::query()->where('student_code', 'SAFE-RET-001')->firstOrFail();
        $this->actingAs($admin)->delete('/admin/students/' . $student->id)->assertRedirect();

        $this->assertTrue(Student::withTrashed()->findOrFail($student->id)->trashed());
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/recycle-bin')->assertOk()->assertSee('SAFE-RET-001');
        $this->actingAs($admin)
            ->post('/admin/recycle-bin/students/' . $student->id . '/restore')
            ->assertRedirect('/admin/recycle-bin')
            ->assertSessionHasNoErrors();

        $this->assertFalse(Student::query()->findOrFail($student->id)->trashed());
        $this->assertDatabaseHas('students', ['id' => $student->id, 'student_code' => 'SAFE-RET-001']);
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'is_active' => false]);
    }

}
