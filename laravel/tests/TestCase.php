<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Plugins\PluginManager;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        refreshTestDatabase as protected refreshTestDatabaseWithTransactions;
        beginDatabaseTransaction as protected beginDatabaseTransactionWithTransactions;
    }

    protected function refreshTestDatabase(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // MySQL DDL implicitly commits. Re-fresh each test instead of wrapping
            // runtime plugin migrations in a transaction/savepoint.
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            return;
        }

        $this->refreshTestDatabaseWithTransactions();
    }

    protected function beginDatabaseTransaction(): void
    {
        if (DB::getDriverName() === 'mysql') {
            return;
        }

        $this->beginDatabaseTransactionWithTransactions();
    }

    protected string $tempLockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempLockPath = sys_get_temp_dir() . '/aec_lms_test_' . uniqid('', true) . '.lock';
        config(['lms.install_lock_path' => $this->tempLockPath]);
        config(['lms.backup_path' => sys_get_temp_dir() . '/aec_lms_backups_' . uniqid('', true)]);
        config(['lms.plugin_signing_key' => 'test-signing-key-32bytes-minimum-secret']);
        config(['lms.backup_encryption_key' => 'test-backup-encryption-secret-key']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempLockPath)) {
            @unlink($this->tempLockPath);
        }
        parent::tearDown();
    }

    protected function markSystemInstalled(): void
    {
        file_put_contents($this->tempLockPath, json_encode(['installed' => true, 'legacy_records_imported' => false]));
        $this->seed(CoreRolesAndPermissionsSeeder::class);
    }

    protected function createUserWithRole(string $roleSlug, ?int $branchId = null, string $email = ''): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleSlug) . ' User',
            'email' => $email !== '' ? $email : $roleSlug . '_' . uniqid() . '@aec.test',
            'password' => Hash::make('StrongPassword!2026'),
            'branch_id' => $branchId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        if ($role = Role::query()->where('slug', $roleSlug)->first()) {
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    protected function activatePlugins(array $slugs): void
    {
        $manager = app(PluginManager::class);
        $manager->syncCatalog();
        foreach ($slugs as $slug) {
            $manager->install($slug);
            $manager->enable($slug);
        }
    }
}
