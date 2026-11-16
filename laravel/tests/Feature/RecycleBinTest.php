<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class RecycleBinTest extends TestCase
{
    public function test_student_delete_preserves_history_and_admin_can_restore_previous_account_state(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $learner = $this->createUserWithRole('student');

        $student = Student::query()->create([
            'user_id' => $learner->id,
            'student_code' => 'RECYCLE-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)->delete('/admin/students/' . $student->id)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $learner->id, 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/recycle-bin')
            ->assertOk()
            ->assertSee('RECYCLE-001');

        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $student->id . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $learner->id, 'is_active' => true]);
    }

    public function test_non_admin_cannot_view_or_restore_recycle_bin_records(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->get('/admin/recycle-bin')->assertForbidden();
        $this->actingAs($student)->post('/admin/recycle-bin/students/1/restore')->assertForbidden();
    }
}
