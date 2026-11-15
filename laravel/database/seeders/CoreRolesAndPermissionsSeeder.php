<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds ONLY system roles, permissions, and baseline system configuration.
 * Never imports legacy users, students, teachers, courses, payments, or operational files.
 */
class CoreRolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin' => ['name' => 'Administrator', 'description' => 'Full Core and Plugin governance', 'is_system' => true],
            'branch_manager' => ['name' => 'Branch Manager', 'description' => 'Branch-isolated operations manager', 'is_system' => true],
            'teacher' => ['name' => 'Teacher', 'description' => 'Instructor and assessment evaluator', 'is_system' => true],
            'accountant' => ['name' => 'Accountant', 'description' => 'Fees, invoices, and payment reconciliation', 'is_system' => true],
            'student' => ['name' => 'Student', 'description' => 'Enrolled learner', 'is_system' => true],
            'guardian' => ['name' => 'Guardian', 'description' => 'Parent/guardian observer', 'is_system' => true],
        ];

        foreach ($roles as $slug => $meta) {
            Role::query()->updateOrCreate(['slug' => $slug], $meta);
        }

        $permissions = [
            ['slug' => 'core.dashboard.view', 'name' => 'View Admin Dashboard', 'module' => 'core'],
            ['slug' => 'core.users.manage', 'name' => 'Manage Users and Roles', 'module' => 'core'],
            ['slug' => 'core.plugins.manage', 'name' => 'Install, Enable, Configure & Uninstall Plugins', 'module' => 'core'],
            ['slug' => 'core.updates.manage', 'name' => 'Manage Core & Plugin Updates', 'module' => 'core'],
            ['slug' => 'core.backups.manage', 'name' => 'Create & Restore Encrypted Backups', 'module' => 'core'],
            ['slug' => 'core.audit.view', 'name' => 'View Security Audit Logs', 'module' => 'core'],
            ['slug' => 'core.settings.manage', 'name' => 'Manage Core System Settings', 'module' => 'core'],
        ];

        $adminRole = Role::query()->where('slug', 'admin')->first();
        foreach ($permissions as $perm) {
            $record = Permission::query()->updateOrCreate(['slug' => $perm['slug']], $perm);
            if ($adminRole) {
                $adminRole->permissions()->syncWithoutDetaching([$record->id]);
            }
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'legacy_records_imported'],
            ['value' => 'false', 'is_encrypted' => false]
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => 'core_version'],
            ['value' => (string) config('lms.core_version', '2.0.0'), 'is_encrypted' => false]
        );
    }
}
