<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Modules\StudentManagement\Models\Student;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentManagementProvisioningSecurityTest extends TestCase
{
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

    public function test_email_uniqueness_is_case_insensitive_for_store_and_update(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Legacy Case Learner',
            'email' => 'legacy.case@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'LEGACY-CASE-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $existing = Student::query()->where('student_code', 'LEGACY-CASE-001')->firstOrFail();
        DB::table('users')->where('id', $existing->user_id)->update(['email' => 'LEGACY.CASE@AEC.TEST']);

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Case Variant Duplicate',
            'email' => 'legacy.case@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'LEGACY-CASE-DUPLICATE',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'legacy.case@aec.test']);
        $this->assertDatabaseMissing('students', ['student_code' => 'LEGACY-CASE-DUPLICATE']);

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Second Case Learner',
            'email' => 'second.case@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'SECOND-CASE-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $second = Student::query()->where('student_code', 'SECOND-CASE-001')->firstOrFail();
        $this->actingAs($admin)->put('/admin/students/' . $second->id, [
            'name' => 'Second Case Learner',
            'email' => 'LEGACY.CASE@AEC.TEST',
            'student_code' => 'SECOND-CASE-001',
            'status' => 'active',
        ])->assertSessionHasErrors('email');

        $this->assertSame('second.case@aec.test', $second->user()->value('email'));
    }

    public function test_generated_student_code_retries_a_unique_index_collision(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Existing Code Learner',
            'email' => 'existing.code@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'STU-COLLID',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $generated = ['COLLID', 'RETRY1'];
        Str::createRandomStringsUsing(function (int $length) use (&$generated): string {
            return array_shift($generated) ?? str_repeat('A', $length);
        });

        try {
            $this->actingAs($admin)->post('/admin/students', [
                'name' => 'Retried Code Learner',
                'email' => 'retried.code@aec.test',
                'password' => 'StrongPassword123!',
            ])->assertRedirect()->assertSessionHasNoErrors();
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertDatabaseHas('students', [
            'student_code' => 'STU-RETRY1',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'retried.code@aec.test',
        ]);
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
