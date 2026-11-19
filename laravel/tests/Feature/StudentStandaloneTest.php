<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_deleting_student_moves_it_to_recycle_bin_and_admin_can_restore_it(): void
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
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'is_active' => true]);
    }


    public function test_admin_can_change_student_branch_and_reset_password_from_edit_form(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);
        $admin = $this->createUserWithRole('admin');

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'STU-EDIT-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Student Edit Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'STU-EDIT-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Student Edit Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Branch Move Learner',
            'email' => 'branch.move@aec.test',
            'password' => 'InitialPassword123!',
            'student_code' => 'BRANCH-MOVE-001',
            'branch_id' => $branchA,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student = Student::query()->where('student_code', 'BRANCH-MOVE-001')->firstOrFail();
        $this->actingAs($admin)->get('/admin/students')
            ->assertOk()
            ->assertSee('name="branch_id"', false)
            ->assertSee('name="password"', false)
            ->assertSee('Student Edit Branch B');

        $this->actingAs($admin)->put('/admin/students/' . $student->id, [
            'name' => 'Branch Move Learner',
            'email' => 'branch.move@aec.test',
            'phone' => '01700000000',
            'student_code' => 'BRANCH-MOVE-001',
            'branch_id' => $branchB,
            'status' => 'active',
            'password' => 'ReplacementPassword456!',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student->refresh();
        $student->load('user');
        $this->assertSame((int) $branchB, (int) $student->branch_id);
        $this->assertSame((int) $branchB, (int) $student->user->branch_id);
        $this->assertTrue(Hash::check('ReplacementPassword456!', $student->user->password));
        $this->assertFalse(Hash::check('InitialPassword123!', $student->user->password));
    }

}
