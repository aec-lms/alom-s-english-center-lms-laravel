<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentManagementProvisioningSecurityTest extends TestCase
{
    public function test_student_store_and_update_normalize_email_and_student_code_before_validation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Normalized Learner',
            'email' => ' Mixed.Case@aec.test ',
            'password' => 'StrongPassword123!',
            'student_code' => ' mixed-code-001 ',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student = Student::query()->where('student_code', 'MIXED-CODE-001')->firstOrFail();
        $student->load('user');
        $this->assertSame('mixed.case@aec.test', $student->user->email);

        $this->actingAs($admin)->put('/admin/students/' . $student->id, [
            'name' => 'Normalized Learner Updated',
            'email' => ' Updated.Case@aec.test ',
            'student_code' => ' updated-code-002 ',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student->refresh()->load('user');
        $this->assertSame('updated.case@aec.test', $student->user->email);
        $this->assertSame('UPDATED-CODE-002', $student->student_code);
    }

    public function test_student_creation_rejects_case_insensitive_legacy_email_and_code_collisions(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $existingUser = User::factory()->create(['email' => 'Legacy.Case@aec.test']);
        Student::query()->create([
            'user_id' => $existingUser->id,
            'student_code' => 'legacy-code-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Duplicate Identity Learner',
            'email' => 'legacy.case@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => ' LEGACY-CODE-001 ',
            'status' => 'active',
        ])->assertSessionHasErrors(['email', 'student_code']);

        $this->assertDatabaseMissing('students', ['student_code' => 'LEGACY-CODE-001']);
    }

    public function test_generated_student_code_retries_after_a_collision(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $existingUser = User::factory()->create(['email' => 'existing.code@aec.test']);
        Student::query()->create([
            'user_id' => $existingUser->id,
            'student_code' => 'STU-AAAAAAAAAA',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $sequence = ['AAAAAAAAAA', 'BBBBBBBBBB'];
        Str::createRandomStringsUsing(function (int $length) use (&$sequence): string {
            return array_shift($sequence) ?? str_repeat('C', $length);
        });

        try {
            $this->actingAs($admin)->post('/admin/students', [
                'name' => 'Generated Code Retry Learner',
                'email' => 'generated.retry@aec.test',
                'password' => 'StrongPassword123!',
                'status' => 'active',
            ])->assertRedirect()->assertSessionHasNoErrors();
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertDatabaseHas('students', ['student_code' => 'STU-BBBBBBBBBB']);
        $this->assertDatabaseMissing('students', ['student_code' => 'STU-AAAAAAAAAA', 'user_id' => User::query()->where('email', 'generated.retry@aec.test')->value('id')]);
    }

    public function test_student_account_is_not_created_when_canonical_student_role_is_missing(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        Role::query()->where('slug', 'student')->delete();

        $this->actingAs($admin)
            ->post('/admin/students', [
                'name' => 'Role Missing Learner',
                'email' => 'role.missing@aec.test',
                'password' => 'StrongPassword123!',
                'student_code' => 'ROLE-MISSING-001',
                'status' => 'active',
            ])
            ->assertStatus(503);

        $this->assertDatabaseMissing('users', ['email' => 'role.missing@aec.test']);
        $this->assertDatabaseMissing('students', ['student_code' => 'ROLE-MISSING-001']);
    }

    public function test_student_account_is_not_created_when_audit_storage_is_unavailable(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        Schema::dropIfExists('audit_logs');

        $this->actingAs($admin)
            ->post('/admin/students', [
                'name' => 'Audit Unavailable Learner',
                'email' => 'audit.unavailable@aec.test',
                'password' => 'StrongPassword123!',
                'student_code' => 'AUDIT-UNAVAILABLE-001',
                'status' => 'active',
            ])
            ->assertStatus(503);

        $this->assertDatabaseMissing('users', ['email' => 'audit.unavailable@aec.test']);
        $this->assertDatabaseMissing('students', ['student_code' => 'AUDIT-UNAVAILABLE-001']);
    }

}
