<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\UploadedFile;
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
            'password' => 'AecTeacher!2026Strong',
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
            'password' => 'AecTeacher!2026Strong',
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

    public function test_recycle_restore_keeps_a_previously_inactive_teacher_account_inactive(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Inactive Retained Instructor',
            'email' => 'inactive.retained@aec.test',
            'employee_code' => 'SAFE-TEACH-INACTIVE-001',
            'specialization' => 'English Grammar',
            'password' => 'AecTeacher!2026Strong',
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'SAFE-TEACH-INACTIVE-001')->firstOrFail();
        $this->assertFalse((bool) $teacher->user->is_active);

        $this->actingAs($admin)->delete('/admin/teachers/' . $teacher->id)->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'is_active' => false]);

        $this->actingAs($admin)
            ->post('/admin/recycle-bin/teachers/' . $teacher->id . '/restore')
            ->assertRedirect('/admin/recycle-bin')
            ->assertSessionHasNoErrors();

        $this->assertFalse(Teacher::query()->findOrFail($teacher->id)->trashed());
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'is_active' => false]);
    }

    public function test_teacher_creation_rolls_back_when_audit_persistence_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit storage unavailable.'));
        $this->app->instance(AuditLogger::class, $audit);

        $response = $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Audit Rollback Teacher',
            'email' => 'audit.rollback@aec.test',
            'employee_code' => 'AUDIT-ROLLBACK-001',
            'specialization' => 'English Grammar',
            'password' => 'AecTeacher!2026Strong',
            'is_active' => '1',
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'audit.rollback@aec.test']);
        $this->assertDatabaseMissing('teachers', ['employee_code' => 'AUDIT-ROLLBACK-001']);
    }

    public function test_teacher_photo_upload_uses_server_detected_extension(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $image = UploadedFile::fake()->image('teacher-photo.jpg');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Safe Upload Teacher',
            'email' => 'safe.upload@aec.test',
            'employee_code' => 'SAFE-UPLOAD-001',
            'specialization' => 'English Grammar',
            'password' => 'AecTeacher!2026Strong',
            'is_active' => '1',
            'photo' => $image,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'SAFE-UPLOAD-001')->firstOrFail();
        $this->assertMatchesRegularExpression('/\\.(?:jpe?g|png|webp)$/i', (string) $teacher->photo_path);
        $this->assertStringNotContainsString('.php', strtolower((string) $teacher->photo_path));
        $controllerSource = file_get_contents(base_path('plugins/modules/teacher-management/Http/Controllers/TeacherController.php'));
        $this->assertIsString($controllerSource);
        $this->assertStringNotContainsString('getClientOriginalExtension()', $controllerSource);
        $this->assertStringContainsString('guessExtension()', $controllerSource);
        $storedPath = public_path(ltrim((string) $teacher->photo_path, '/'));
        $this->assertFileExists($storedPath);

        @unlink($storedPath);
    }


    public function test_teacher_update_without_is_active_preserves_existing_state(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Preserve Active Teacher',
            'email' => 'preserve.active@aec.test',
            'employee_code' => 'PRESERVE-ACTIVE-001',
            'specialization' => 'English Grammar',
            'password' => 'AecTeacher!2026Strong',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'PRESERVE-ACTIVE-001')->firstOrFail();

        $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Preserve Active Teacher Updated',
            'employee_code' => 'PRESERVE-ACTIVE-001',
            'specialization' => 'English Grammar',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'is_active' => true]);
    }

    public function test_teacher_update_with_explicit_zero_deactivates_teacher_and_linked_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Explicit Deactivate Teacher',
            'email' => 'explicit.deactivate@aec.test',
            'employee_code' => 'EXPLICIT-DEACTIVATE-001',
            'specialization' => 'English Grammar',
            'password' => 'AecTeacher!2026Strong',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'EXPLICIT-DEACTIVATE-001')->firstOrFail();

        $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Explicit Deactivate Teacher',
            'employee_code' => 'EXPLICIT-DEACTIVATE-001',
            'specialization' => 'English Grammar',
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'is_active' => false]);
    }


}
