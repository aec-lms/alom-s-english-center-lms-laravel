<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Installer\FreshInstallerService;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use RuntimeException;
use Tests\TestCase;

class FreshInstallerTest extends TestCase
{
    public function test_fresh_installer_rejects_non_empty_database(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE legacy_students (id INTEGER PRIMARY KEY, name TEXT)');

        $installer = app(FreshInstallerService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty fresh database is required');
        $installer->assertFreshEmptyDatabase($pdo, ':memory:', 'sqlite');
    }

    public function test_fresh_installer_succeeds_on_empty_sqlite_and_seeds_no_legacy_records(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $installer = app(FreshInstallerService::class);
        $installer->assertFreshEmptyDatabase($pdo, ':memory:', 'sqlite');

        $this->markSystemInstalled();

        $this->assertSame('false', SystemSetting::query()->where('key', 'legacy_records_imported')->value('value'));
        $this->assertSame(0, User::query()->count());
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('legacy_students'));
    }


    public function test_fresh_installer_completes_real_empty_database_install_without_returning_secrets(): void
    {
        $originalDefault = config('database.default');
        $originalAppKey = config('app.key');
        $originalSigningKey = config('lms.plugin_signing_key');
        $originalBackupKey = config('lms.backup_encryption_key');
        $envPath = base_path('.env');
        $hadEnv = is_file($envPath);
        $originalEnv = $hadEnv ? file_get_contents($envPath) : null;
        $originalMode = $hadEnv ? (fileperms($envPath) & 0777) : 0600;
        $privatePath = sys_get_temp_dir() . '/aec-installer-private-' . uniqid('', true);

        if (!$hadEnv) {
            file_put_contents($envPath, "APP_KEY=\n");
        }

        config([
            'database.connections.installer_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'installer_test',
            'lms.install_lock_path' => $this->tempLockPath,
            'lms.private_storage_path' => $privatePath,
        ]);
        DB::purge('installer_test');
        DB::setDefaultConnection('installer_test');

        try {
            config(['session.driver' => 'file', 'cache.default' => 'file']);
            $this->get('/install')->assertOk()->assertSee('International LMS Installer');

            $unsafePublicPath = public_path('aec-private-leak-' . uniqid('', true));
            config(['lms.private_storage_path' => $unsafePublicPath]);
            try {
                app(FreshInstallerService::class)->installFresh([
                    'app_name' => 'AEC Installer Test',
                    'app_url' => 'https://aec.test',
                    'admin_name' => 'Fresh Admin',
                    'admin_email' => 'fresh-admin@aec.test',
                    'admin_password' => 'FreshStrongPassword!2026',
                ]);
                $this->fail('Installer accepted a private storage directory inside the public document root.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('outside the public web document root', $exception->getMessage());
            }
            $this->assertDirectoryDoesNotExist($unsafePublicPath);

            config(['lms.private_storage_path' => $privatePath]);
            $result = app(FreshInstallerService::class)->installFresh([
                'app_name' => 'AEC Installer Test',
                'app_url' => 'https://aec.test',
                'admin_name' => 'Fresh Admin',
                'admin_email' => 'fresh-admin@aec.test',
                'admin_password' => 'FreshStrongPassword!2026',
            ]);

            $this->assertSame(['admin_id', 'lock_path'], array_keys($result));
            $this->assertFileExists($this->tempLockPath);
            $this->assertDirectoryExists($privatePath);
            $this->assertTrue(is_writable($privatePath));
            $this->assertTrue(app(FreshInstallerService::class)->isInstalled());

            $admin = User::query()->where('email', 'fresh-admin@aec.test')->firstOrFail();
            $this->assertTrue(Hash::check('FreshStrongPassword!2026', $admin->password));
            $this->assertTrue($admin->hasRole('admin'));
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'core.installed',
                'entity_type' => 'system',
                'entity_id' => 'fresh_install',
                'user_id' => $admin->id,
            ]);
            $this->assertSame('false', SystemSetting::query()->where('key', 'legacy_records_imported')->value('value'));
            $this->assertSame('US', SystemSetting::query()->where('key', 'institution.country_code')->value('value'));
            $this->assertSame('en', SystemSetting::query()->where('key', 'localization.locale')->value('value'));
            $this->assertSame('UTC', SystemSetting::query()->where('key', 'localization.timezone')->value('value'));
            $this->assertSame('USD', SystemSetting::query()->where('key', 'finance.default_currency')->value('value'));
            $this->assertSame(0, User::query()->where('email', 'legacy@example.com')->count());

            $savedEnv = (string) file_get_contents($envPath);
            $this->assertStringContainsString('APP_KEY="base64:', $savedEnv);
            $this->assertStringContainsString('AEC_PLUGIN_SIGNING_KEY="', $savedEnv);
            $this->assertStringContainsString('AEC_BACKUP_ENCRYPTION_KEY="', $savedEnv);
            $this->assertGreaterThanOrEqual(64, strlen((string) config('lms.plugin_signing_key')));
            $this->assertSame(32, strlen((string) base64_decode((string) config('lms.backup_encryption_key'), true)));
        } finally {
            DB::disconnect('installer_test');
            @rmdir($privatePath);
            DB::setDefaultConnection($originalDefault);
            config([
                'database.default' => $originalDefault,
                'app.key' => $originalAppKey,
                'lms.plugin_signing_key' => $originalSigningKey,
                'lms.backup_encryption_key' => $originalBackupKey,
            ]);

            if ($hadEnv && is_string($originalEnv)) {
                file_put_contents($envPath, $originalEnv, LOCK_EX);
                @chmod($envPath, $originalMode);
            } elseif (!$hadEnv) {
                @unlink($envPath);
            }
        }
    }


    public function test_browser_installer_page_uses_file_sessions_before_database_installation(): void
    {
        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
            'lms.install_lock_path' => $this->tempLockPath,
        ]);

        $this->get('/install')->assertOk()->assertSee('International LMS Installer');
    }

    public function test_installer_routes_are_locked_after_installation(): void
    {
        $this->markSystemInstalled();

        $this->get('/install')->assertStatus(404);
    }
}
