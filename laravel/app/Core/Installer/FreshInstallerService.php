<?php

declare(strict_types=1);

namespace App\Core\Installer;

use App\Core\Security\AuditLogger;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\CoreRolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use RuntimeException;

class FreshInstallerService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function isInstalled(): bool
    {
        return is_file((string) config('lms.install_lock_path'));
    }

    public function checkServerRequirements(): array
    {
        $requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'zip', 'fileinfo'];
        $extensions = [];
        foreach ($requiredExtensions as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        $directories = [
            'storage/app' => is_writable(storage_path('app')),
            'storage/framework' => is_writable(storage_path('framework')),
            'storage/logs' => is_writable(storage_path('logs')),
            'bootstrap/cache' => is_writable(base_path('bootstrap/cache')),
        ];

        return [
            'php_version' => PHP_VERSION,
            'php_ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'extensions' => $extensions,
            'directories' => $directories,
        ];
    }

    /**
     * Enforces fresh-database-only policy: rejects any database that already contains tables.
     * Never drops, truncates, or imports legacy operational data.
     */
    public function assertFreshEmptyDatabase(PDO $pdo, string $databaseName, string $driver = 'mysql'): void
    {
        if ($driver === 'sqlite') {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
            $stmt->execute([$databaseName]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                throw new RuntimeException("Installation refused: database '{$databaseName}' already contains {$count} table(s). A completely empty fresh database is required.");
            }
            return;
        }

        if (count($tables) > 0) {
            throw new RuntimeException('Installation refused: database already contains existing tables. A completely empty fresh database is required.');
        }
    }

    public function installFresh(array $config): array
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('AEC LMS is already installed and locked.');
        }

        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $dbName = (string) DB::connection()->getDatabaseName();

        $this->assertFreshEmptyDatabase($pdo, $dbName, $driver);

        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $signingKey = bin2hex(random_bytes(32));
        $backupKey = base64_encode(random_bytes(32));

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => CoreRolesAndPermissionsSeeder::class, '--force' => true]);

        $admin = User::query()->create([
            'name' => (string) $config['admin_name'],
            'email' => strtolower(trim((string) $config['admin_email'])),
            'password' => Hash::make((string) $config['admin_password']),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->sync([$adminRole->id]);
        }

        SystemSetting::query()->updateOrCreate(['key' => 'site_name'], ['value' => (string) ($config['app_name'] ?? 'AEC LMS')]);
        SystemSetting::query()->updateOrCreate(['key' => 'site_url'], ['value' => (string) ($config['app_url'] ?? 'https://alomsenglishcenter.com')]);
        SystemSetting::query()->updateOrCreate(['key' => 'legacy_records_imported'], ['value' => 'false']);

        $lockPayload = json_encode([
            'installed_at' => now()->toIso8601String(),
            'core_version' => config('lms.core_version'),
            'legacy_records_imported' => false,
            'admin_user_id' => $admin->id,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $lockPath = (string) config('lms.install_lock_path');
        if (!is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0750, true);
        }
        file_put_contents($lockPath, $lockPayload);

        $this->audit->log('core.installed', 'system', 'fresh_install', ['legacy_records_imported' => false], $admin->id);

        return [
            'admin_id' => $admin->id,
            'generated_keys' => [
                'APP_KEY' => $appKey,
                'AEC_PLUGIN_SIGNING_KEY' => $signingKey,
                'AEC_BACKUP_ENCRYPTION_KEY' => $backupKey,
            ],
            'lock_path' => $lockPath,
        ];
    }
}
