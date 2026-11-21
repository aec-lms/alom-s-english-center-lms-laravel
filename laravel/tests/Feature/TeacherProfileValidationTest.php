<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Tests\TestCase;

final class TeacherProfileValidationTest extends TestCase
{
    public function test_teacher_creation_rolls_back_account_and_profile_when_required_audit_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'teacher.created') {
                throw new \\RuntimeException('forced teacher audit failure');
            }
        });

        try {
            try {
                $this->actingAs($admin)->post('/admin/teachers', [
                    'name' => 'Audit Failure Teacher',
                    'email' => 'audit.failure.teacher@aec.test',
                    'employee_code' => 'AUDIT-FAIL-TEACHER-001',
                    'specialization' => 'English Grammar',
                    'is_active' => '1',
                ]);
                $this->fail('Teacher creation must fail when required audit storage fails.');
            } catch (\\RuntimeException $exception) {
                $this->assertSame('forced teacher audit failure', $exception->getMessage());
            }
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseMissing('users', ['email' => 'audit.failure.teacher@aec.test']);
        $this->assertDatabaseMissing('teachers', ['employee_code' => 'AUDIT-FAIL-TEACHER-001']);
    }

    public function test_teacher_update_rejects_an_email_already_owned_by_another_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $other = $this->createUserWithRole('student');
        $other->update(['email' => 'already.used@aec.test']);

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Validation Teacher',
            'email' => 'validation.teacher@aec.test',
            'employee_code' => 'VALIDATE-TEACH-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'VALIDATE-TEACH-001')->firstOrFail();

        $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Validation Teacher',
            'email' => 'already.used@aec.test',
            'employee_code' => 'VALIDATE-TEACH-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertSame('validation.teacher@aec.test', $teacher->fresh()->user->email);
    }

    public function test_teacher_profile_update_cannot_reactivate_a_separately_disabled_user_account(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Security Disabled Teacher',
            'email' => 'security.disabled@aec.test',
            'employee_code' => 'SECURITY-DISABLED-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'SECURITY-DISABLED-001')->firstOrFail();
        $teacher->user->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Security Disabled Teacher Updated',
            'employee_code' => 'SECURITY-DISABLED-001',
            'specialization' => 'English Grammar',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse((bool) $teacher->fresh()->is_active);
        $this->assertFalse((bool) $teacher->fresh()->user->is_active);
    }

    public function test_only_admin_can_explicitly_reactivate_a_disabled_teacher_account_and_audits_actor(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management', 'branch-management']);
        $admin = $this->createUserWithRole('admin');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'TEACHER-SECURITY-01',
            'name' => 'Teacher Security Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Explicit Activation Teacher',
            'email' => 'explicit.activation@aec.test',
            'employee_code' => 'EXPLICIT-ACTIVATION-001',
            'specialization' => 'English Grammar',
            'branch_id' => $branchId,
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'EXPLICIT-ACTIVATION-001')->firstOrFail();
        $userId = (int) $teacher->user_id;

        $manager = $this->createUserWithRole('branch_manager');
        $manager->forceFill(['branch_id' => $branchId])->save();
        $managerRole = Role::query()->where('slug', 'branch_manager')->firstOrFail();
        $teacherManagePermissionId = DB::table('permissions')->where('slug', 'teachers.manage')->value('id');
        $this->assertNotNull($teacherManagePermissionId);
        $managerRole->permissions()->syncWithoutDetaching([(int) $teacherManagePermissionId]);

        $this->actingAs($manager)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Explicit Activation Teacher',
            'employee_code' => 'EXPLICIT-ACTIVATION-001',
            'specialization' => 'English Grammar',
            'branch_id' => $branchId,
            'is_active' => '1',
        ])->assertForbidden();

        $this->assertFalse((bool) $teacher->fresh()->is_active);
        $this->assertFalse((bool) $teacher->fresh()->user->is_active);

        $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Explicit Activation Teacher',
            'employee_code' => 'EXPLICIT-ACTIVATION-001',
            'specialization' => 'English Grammar',
            'branch_id' => $branchId,
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue((bool) $teacher->fresh()->is_active);
        $this->assertTrue((bool) $teacher->fresh()->user->is_active);

        $statusEvent = AuditLog::query()
            ->where('action', 'teacher.account_status_changed')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $userId)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $statusEvent->user_id);
        $this->assertSame((int) $teacher->id, $statusEvent->metadata['teacher_id']);
        $this->assertFalse($statusEvent->metadata['before']);
        $this->assertTrue($statusEvent->metadata['after']);
    }

    public function test_mysql_teacher_reactivation_holds_admin_role_until_commit(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher account reactivation role-lock proof requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Role Lock Teacher',
            'email' => 'role.lock.teacher@aec.test',
            'employee_code' => 'ROLE-LOCK-TEACH-001',
            'specialization' => 'English Grammar',
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'ROLE-LOCK-TEACH-001')->firstOrFail();
        $adminRoleId = (int) DB::table('roles')->where('slug', 'admin')->value('id');
        $this->assertGreaterThan(0, $adminRoleId);

        $primary = DB::connection();
        $secondaryName = 'teacher_admin_role_revoke';
        config(['database.connections.' . $secondaryName => config('database.connections.' . DB::getDefaultConnection())]);
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
                'name' => 'Role Lock Teacher',
                'employee_code' => 'ROLE-LOCK-TEACH-001',
                'specialization' => 'English Grammar',
                'is_active' => '1',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertTrue((bool) $teacher->fresh()->is_active);
            $this->assertTrue((bool) $teacher->fresh()->user->is_active);

            $secondary->beginTransaction();
            try {
                $secondary->table('role_user')
                    ->where('user_id', $admin->id)
                    ->where('role_id', $adminRoleId)
                    ->delete();
                $this->fail('Admin role revocation must wait while the teacher reactivation transaction holds the role pivot.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $primary->commit();

            $secondary->table('role_user')
                ->where('user_id', $admin->id)
                ->where('role_id', $adminRoleId)
                ->delete();

            $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
                'name' => 'Must Not Update After Role Revocation',
                'employee_code' => 'ROLE-LOCK-TEACH-001',
                'specialization' => 'English Grammar',
                'is_active' => '1',
            ])->assertForbidden();

            $this->assertSame('Role Lock Teacher', $teacher->fresh()->user->name);
            $this->assertTrue((bool) $teacher->fresh()->is_active);
            $this->assertTrue((bool) $teacher->fresh()->user->is_active);
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

    public function test_teacher_creation_fails_closed_when_teacher_role_is_missing(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');
        Role::query()->where('slug', 'teacher')->delete();

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Role Missing Teacher',
            'email' => 'role.missing.teacher@aec.test',
            'employee_code' => 'ROLE-MISSING-TEACH-001',
            'specialization' => 'English Grammar',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'role.missing.teacher@aec.test']);
        $this->assertDatabaseMissing('teachers', ['employee_code' => 'ROLE-MISSING-TEACH-001']);
    }

    public function test_teacher_emails_are_normalized_and_case_variant_legacy_rows_are_rejected(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Case Normalized Teacher',
            'email' => 'Case.Normalized@AEC.TEST',
            'employee_code' => 'CASE-NORMALIZED-001',
            'specialization' => 'English Grammar',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $existing = Teacher::query()->where('employee_code', 'CASE-NORMALIZED-001')->firstOrFail();
        $this->assertDatabaseHas('users', [
            'id' => $existing->user_id,
            'email' => 'case.normalized@aec.test',
        ]);
        DB::table('users')->where('id', $existing->user_id)->update(['email' => 'CASE.NORMALIZED@AEC.TEST']);

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Case Variant Duplicate Teacher',
            'email' => 'case.normalized@aec.test',
            'employee_code' => 'CASE-NORMALIZED-DUPLICATE',
            'specialization' => 'English Grammar',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('teachers', ['employee_code' => 'CASE-NORMALIZED-DUPLICATE']);

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Second Case Teacher',
            'email' => 'second.case.teacher@aec.test',
            'employee_code' => 'SECOND-CASE-TEACH-001',
            'specialization' => 'English Grammar',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $second = Teacher::query()->where('employee_code', 'SECOND-CASE-TEACH-001')->firstOrFail();
        $this->actingAs($admin)->put('/admin/teachers/' . $second->id, [
            'name' => 'Second Case Teacher',
            'email' => 'CASE.NORMALIZED@AEC.TEST',
            'employee_code' => 'SECOND-CASE-TEACH-001',
            'specialization' => 'English Grammar',
        ])->assertSessionHasErrors('email');

        $this->assertSame('second.case.teacher@aec.test', $second->fresh()->user->email);
    }

    public function test_teacher_social_links_must_be_valid_urls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Unsafe Social Link Teacher',
            'email' => 'unsafe.links@aec.test',
            'employee_code' => 'VALIDATE-TEACH-002',
            'specialization' => 'English Grammar',
            'youtube_url' => 'javascript:alert(1)',
            'facebook_url' => 'not a URL',
            'is_active' => '1',
        ])->assertSessionHasErrors(['youtube_url', 'facebook_url']);

        $this->assertDatabaseMissing('teachers', ['employee_code' => 'VALIDATE-TEACH-002']);
    }
    public function test_mysql_teacher_creation_holds_permission_grant_until_commit_and_rejects_after_revocation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher permission revocation serialization requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management', 'branch-management']);
        $manager = $this->createUserWithRole('branch_manager');
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'TEACHER-PERMISSION-RACE',
            'name' => 'Teacher Permission Race Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager->forceFill(['branch_id' => $branchId])->save();

        $roleId = (int) DB::table('roles')->where('slug', 'branch_manager')->value('id');
        $permissionId = (int) DB::table('permissions')->where('slug', 'teachers.manage')->value('id');
        $this->assertGreaterThan(0, $roleId);
        $this->assertGreaterThan(0, $permissionId);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        $default = DB::getDefaultConnection();
        $secondaryName = 'teacher_permission_race_' . bin2hex(random_bytes(3));
        config(['database.connections.' . $secondaryName => config('database.connections.' . $default)]);
        DB::purge($secondaryName);
        $primary = DB::connection($default);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            $this->actingAs($manager)->post('/admin/teachers', [
                'name' => 'Permission Locked Teacher',
                'email' => 'permission.locked.teacher@aec.test',
                'employee_code' => 'PERMISSION-LOCKED-TEACHER',
                'specialization' => 'English',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $secondary->beginTransaction();
            try {
                $secondary->table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->delete();
                $this->fail('Teacher permission revocation must wait while creation holds the permission grant lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $primary->commit();

            $secondary->table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();

            $this->actingAs($manager)->post('/admin/teachers', [
                'name' => 'Permission Revoked Teacher',
                'email' => 'permission.revoked.teacher@aec.test',
                'employee_code' => 'PERMISSION-REVOKED-TEACHER',
                'specialization' => 'English',
            ])->assertForbidden();

            $this->assertDatabaseHas('teachers', ['employee_code' => 'PERMISSION-LOCKED-TEACHER']);
            $this->assertDatabaseMissing('users', ['email' => 'permission.revoked.teacher@aec.test']);
            $this->assertDatabaseMissing('teachers', ['employee_code' => 'PERMISSION-REVOKED-TEACHER']);
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

}
