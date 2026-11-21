<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Plugins\Modules\StudentManagement\Models\Student;
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

    public function test_student_creation_and_update_reject_case_insensitive_legacy_code_collisions(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Collision Target',
            'email' => 'collision.target@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'TARGET-CODE-01',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $target = Student::query()->where('student_code', 'TARGET-CODE-01')->firstOrFail();

        $legacy = $this->createLegacyStudentForIdentityTest('legacy.code@aec.test', 'legacy-code-001');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Duplicate Code Learner',
            'email' => 'duplicate.code@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => ' LEGACY-CODE-001 ',
            'status' => 'active',
        ])->assertSessionHasErrors('student_code');

        $this->actingAs($admin)->put('/admin/students/' . $target->id, [
            'name' => 'Collision Target',
            'email' => 'collision.target@aec.test',
            'student_code' => ' LEGACY-CODE-001 ',
            'status' => 'active',
        ])->assertSessionHasErrors('student_code');

        $this->assertSame('TARGET-CODE-01', $target->fresh()->student_code);
        $this->assertSame(1, Student::query()->whereRaw('UPPER(student_code) = ?', ['LEGACY-CODE-001'])->count());
        $this->assertDatabaseHas('students', ['id' => $legacy->id, 'student_code' => 'legacy-code-001']);
    }

    public function test_email_uniqueness_is_case_insensitive_for_store_and_update(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Legacy Case Learner',
            'email' => 'Legacy.Case@AEC.TEST',
            'password' => 'StrongPassword123!',
            'student_code' => 'LEGACY-CASE-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $existing = Student::query()->where('student_code', 'LEGACY-CASE-001')->firstOrFail();
        $this->assertDatabaseHas('users', ['id' => $existing->user_id, 'email' => 'legacy.case@aec.test']);
        DB::table('users')->where('id', $existing->user_id)->update(['email' => 'LEGACY.CASE@AEC.TEST']);

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Case Variant Duplicate',
            'email' => 'legacy.case@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'LEGACY-CASE-DUPLICATE',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, DB::table('users')->whereRaw('LOWER(email) = ?', ['legacy.case@aec.test'])->count());
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
            'student_code' => 'STU-COLLISION1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $generated = ['COLLISION1', 'RETRYCODE1'];
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
            'student_code' => 'STU-RETRYCODE1',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'retried.code@aec.test',
        ]);
    }

    public function test_generated_student_code_retry_exhaustion_rolls_back_account_creation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/students', [
            'name' => 'Existing Collision Learner',
            'email' => 'collision.existing@aec.test',
            'password' => 'StrongPassword123!',
            'student_code' => 'STU-COLLISION1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Str::createRandomStringsUsing(fn (int $length): string => 'COLLISION1');

        try {
            $this->actingAs($admin)->post('/admin/students', [
                'name' => 'Exhausted Collision Learner',
                'email' => 'collision.exhausted@aec.test',
                'password' => 'StrongPassword123!',
            ])->assertSessionHasErrors('student_code');
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertDatabaseMissing('users', ['email' => 'collision.exhausted@aec.test']);
        $this->assertSame(1, Student::query()->count());
    }

    public function test_explicit_student_code_unique_index_race_returns_validation_error_and_rolls_back(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $dispatcher = Student::getEventDispatcher();
        $event = 'eloquent.creating: ' . Student::class;
        $injected = false;

        $dispatcher->listen($event, function (Student $student) use (&$injected): void {
            if ($injected || $student->student_code !== 'RACE-CODE-001') {
                return;
            }

            $injected = true;
            Student::withoutEvents(function (): void {
                $user = User::query()->create([
                    'name' => 'Concurrent Student',
                    'email' => 'concurrent.race@aec.test',
                    'password' => Hash::make('StrongPassword123!'),
                    'is_active' => true,
                ]);

                Student::query()->create([
                    'user_id' => $user->id,
                    'student_code' => 'RACE-CODE-001',
                    'admission_date' => now()->toDateString(),
                    'status' => 'active',
                ]);
            });
        });

        try {
            $this->actingAs($admin)->post('/admin/students', [
                'name' => 'Explicit Race Learner',
                'email' => 'explicit.race@aec.test',
                'password' => 'StrongPassword123!',
                'student_code' => 'RACE-CODE-001',
                'status' => 'active',
            ])->assertSessionHasErrors('student_code');
        } finally {
            $dispatcher->forget($event);
        }

        $this->assertTrue($injected, 'The test must reach the database insert race after validation.');
        $this->assertDatabaseMissing('users', ['email' => 'explicit.race@aec.test']);
        $this->assertDatabaseMissing('users', ['email' => 'concurrent.race@aec.test']);
        $this->assertDatabaseMissing('students', ['student_code' => 'RACE-CODE-001']);
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

    private function createLegacyStudentForIdentityTest(string $email, string $studentCode): Student
    {
        $user = User::query()->create([
            'name' => 'Legacy Student',
            'email' => $email,
            'password' => Hash::make('StrongPassword123!'),
            'is_active' => true,
        ]);

        return Student::query()->create([
            'user_id' => $user->id,
            'student_code' => $studentCode,
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

}
