<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Installer\FreshInstallerService;
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
            $this->assertFileDoesNotExist($this->tempLockPath . '.in-progress', 'A successful install must clear its progress marker.');
            $this->assertFileExists($this->tempLockPath . '.mutex', 'The OS-managed installer mutex must be created for serialized installs.');
            $this->assertDirectoryExists($privatePath);
            $this->assertTrue(is_writable($privatePath));
            $this->assertTrue(app(FreshInstallerService::class)->isInstalled());

            $admin = User::query()->where('email', 'fresh-admin@aec.test')->firstOrFail();
            $this->assertTrue(Hash::check('FreshStrongPassword!2026', $admin->password));
            $this->assertTrue($admin->hasRole('admin'));
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


    public function test_failed_migration_releases_mutex_and_allows_a_clean_retry(): void
    {
        $originalDefault = config('database.default');
        $originalAppKey = config('app.key');
        $originalSigningKey = config('lms.plugin_signing_key');
        $originalBackupKey = config('lms.backup_encryption_key');
        $root = sys_get_temp_dir() . '/aec-installer-retry-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);

        $lockPath = $root . '/installed.lock';
        $envPath = $root . '/.env';
        $privatePath = $root . '/private';
        $backupPath = $root . '/backups';
        file_put_contents($envPath, "APP_KEY=\n");

        config([
            'database.connections.installer_failure_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'installer_failure_test',
            'lms.install_lock_path' => $lockPath,
            'lms.env_path' => $envPath,
            'lms.private_storage_path' => $privatePath,
            'lms.backup_path' => $backupPath,
        ]);
        DB::purge('installer_failure_test');
        DB::setDefaultConnection('installer_failure_test');

        $installConfig = [
            'app_name' => 'AEC Retry Test',
            'app_url' => 'https://aec-retry.test',
            'admin_name' => 'Retry Admin',
            'admin_email' => 'retry-admin@aec.test',
            'admin_password' => 'RetryStrongPassword!2026',
            'country_code' => 'BD',
            'locale' => 'en',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ];

        $consoleKernel = app(\Illuminate\Contracts\Console\Kernel::class);
        try {
            \Illuminate\Support\Facades\Artisan::shouldReceive('call')
                ->once()
                ->with('migrate', ['--force' => true])
                ->andReturn(1);
            \Illuminate\Support\Facades\Artisan::shouldReceive('output')
                ->andReturn('simulated migration failure');

            try {
                app(FreshInstallerService::class)->installFresh($installConfig);
                $this->fail('Installer unexpectedly completed while the migration command reported failure.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Core database migrations failed', $exception->getMessage());
            } finally {
                \Illuminate\Support\Facades\Artisan::swap($consoleKernel);
            }

            $this->assertFileDoesNotExist($lockPath, 'A failed migration must never create the installed marker.');
            $this->assertFileExists($lockPath . '.in-progress', 'A failed migration must retain its durable phase marker.');
            $this->assertFileExists($lockPath . '.mutex');

            $probe = fopen($lockPath . '.mutex', 'c');
            $this->assertNotFalse($probe);
            $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB), 'The OS mutex must be released after migration failure.');
            flock($probe, LOCK_UN);
            fclose($probe);

            // The failed attempt did not write a success marker or retain the OS lock;
            // retrying the same fresh database must complete normally.
            $result = app(FreshInstallerService::class)->installFresh($installConfig);
            $this->assertFileExists($lockPath);
            $this->assertFileDoesNotExist($lockPath . '.in-progress', 'A clean retry must remove the interrupted-attempt marker.');
            $this->assertSame(1, (int) User::query()->where('email', 'retry-admin@aec.test')->count());
            $this->assertSame(1, (int) $result['admin_id']);
        } finally {
            \Illuminate\Support\Facades\Artisan::swap($consoleKernel);
            DB::disconnect('installer_failure_test');
            DB::setDefaultConnection($originalDefault);
            config([
                'database.default' => $originalDefault,
                'app.key' => $originalAppKey,
                'lms.plugin_signing_key' => $originalSigningKey,
                'lms.backup_encryption_key' => $originalBackupKey,
            ]);

            foreach ([$lockPath, $lockPath . '.mutex', $lockPath . '.in-progress', $envPath] as $file) {
                @unlink($file);
            }
            foreach ([$privatePath, $backupPath] as $directory) {
                @rmdir($directory);
            }
            @rmdir($root);
        }
    }

    public function test_environment_key_persistence_failure_is_recorded_and_can_retry_on_empty_database(): void
    {
        $originalDefault = config('database.default');
        $originalAppKey = config('app.key');
        $originalSigningKey = config('lms.plugin_signing_key');
        $originalBackupKey = config('lms.backup_encryption_key');
        $root = sys_get_temp_dir() . '/aec-installer-env-failure-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);

        $lockPath = $root . '/installed.lock';
        $envPath = $root . '/missing/.env';
        $privatePath = $root . '/private';
        $backupPath = $root . '/backups';

        config([
            'database.connections.installer_env_failure_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'installer_env_failure_test',
            'lms.install_lock_path' => $lockPath,
            'lms.env_path' => $envPath,
            'lms.private_storage_path' => $privatePath,
            'lms.backup_path' => $backupPath,
        ]);
        DB::purge('installer_env_failure_test');
        DB::setDefaultConnection('installer_env_failure_test');

        $installConfig = [
            'app_name' => 'AEC Environment Retry Test',
            'app_url' => 'https://aec-env-retry.test',
            'admin_name' => 'Environment Retry Admin',
            'admin_email' => 'environment-retry-admin@aec.test',
            'admin_password' => 'EnvironmentRetryStrongPassword!2026',
            'country_code' => 'BD',
            'locale' => 'en',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ];

        try {
            try {
                app(FreshInstallerService::class)->installFresh($installConfig);
                $this->fail('Installer unexpectedly persisted generated keys to a missing environment-file path.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('.env file must exist and be writable', $exception->getMessage());
            }

            $this->assertFileDoesNotExist($lockPath);
            $this->assertFileExists($lockPath . '.in-progress', 'Key persistence failure must leave a durable phase marker.');
            $probe = fopen($lockPath . '.mutex', 'c');
            $this->assertNotFalse($probe);
            $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB), 'The mutex must be released after key persistence failure.');
            flock($probe, LOCK_UN);
            fclose($probe);

            // Repair only the missing environment file. The database is still empty,
            // so the interrupted marker can be cleared and installation retried safely.
            mkdir(dirname($envPath), 0700, true);
            file_put_contents($envPath, "APP_KEY=\n");
            $result = app(FreshInstallerService::class)->installFresh($installConfig);

            $this->assertFileExists($lockPath);
            $this->assertFileDoesNotExist($lockPath . '.in-progress');
            $this->assertSame(1, (int) User::query()->where('email', 'environment-retry-admin@aec.test')->count());
            $this->assertGreaterThan(0, (int) $result['admin_id']);
        } finally {
            DB::disconnect('installer_env_failure_test');
            DB::setDefaultConnection($originalDefault);
            config([
                'database.default' => $originalDefault,
                'app.key' => $originalAppKey,
                'lms.plugin_signing_key' => $originalSigningKey,
                'lms.backup_encryption_key' => $originalBackupKey,
            ]);

            foreach ([$lockPath, $lockPath . '.mutex', $lockPath . '.in-progress', $envPath] as $file) {
                @unlink($file);
            }
            foreach ([$privatePath, $backupPath, dirname($envPath)] as $directory) {
                @rmdir($directory);
            }
            @rmdir($root);
        }
    }

    public function test_seeder_failure_never_marks_partial_schema_installed_and_requires_a_fresh_database_for_recovery(): void
    {
        $originalDefault = config('database.default');
        $originalAppKey = config('app.key');
        $originalSigningKey = config('lms.plugin_signing_key');
        $originalBackupKey = config('lms.backup_encryption_key');
        $root = sys_get_temp_dir() . '/aec-installer-seeder-failure-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);

        $lockPath = $root . '/installed.lock';
        $envPath = $root . '/.env';
        $privatePath = $root . '/private';
        $backupPath = $root . '/backups';
        file_put_contents($envPath, "APP_KEY=\n");

        config([
            'database.connections.installer_seeder_failure_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'installer_seeder_failure_test',
            'lms.install_lock_path' => $lockPath,
            'lms.env_path' => $envPath,
            'lms.private_storage_path' => $privatePath,
            'lms.backup_path' => $backupPath,
        ]);
        DB::purge('installer_seeder_failure_test');
        DB::setDefaultConnection('installer_seeder_failure_test');

        $installConfig = [
            'app_name' => 'AEC Seeder Failure Test',
            'app_url' => 'https://aec-seeder-failure.test',
            'admin_name' => 'Seeder Failure Admin',
            'admin_email' => 'seeder-failure-admin@aec.test',
            'admin_password' => 'SeederFailureStrongPassword!2026',
            'country_code' => 'BD',
            'locale' => 'en',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ];

        $consoleKernel = app(\Illuminate\Contracts\Console\Kernel::class);
        try {
            \Illuminate\Support\Facades\Artisan::shouldReceive('call')
                ->once()
                ->with('migrate', ['--force' => true])
                ->andReturnUsing(static function (string $command, array $parameters) use ($consoleKernel): int {
                    return $consoleKernel->call($command, $parameters);
                });
            \Illuminate\Support\Facades\Artisan::shouldReceive('call')
                ->once()
                ->with('db:seed', ['--class' => \Database\Seeders\CoreRolesAndPermissionsSeeder::class, '--force' => true])
                ->andReturn(1);
            \Illuminate\Support\Facades\Artisan::shouldReceive('output')
                ->andReturn('simulated core seeder failure');

            try {
                app(FreshInstallerService::class)->installFresh($installConfig);
                $this->fail('Installer unexpectedly completed after the core seeder reported failure.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Core roles and permissions seeding failed', $exception->getMessage());
            } finally {
                \Illuminate\Support\Facades\Artisan::swap($consoleKernel);
            }

            $this->assertFileDoesNotExist($lockPath, 'A partially migrated database must never receive the installed marker.');
            $this->assertFileExists($lockPath . '.in-progress', 'The interrupted phase marker must survive a seeder failure.');
            $this->assertGreaterThan(0, DB::table('migrations')->count());
            $this->assertSame(0, User::query()->where('email', 'seeder-failure-admin@aec.test')->count());

            $probe = fopen($lockPath . '.mutex', 'c');
            $this->assertNotFalse($probe);
            $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB), 'The mutex must be released after a seeder failure.');
            flock($probe, LOCK_UN);
            fclose($probe);

            // Core migrations may have committed DDL before seeding failed. The
            // fresh-only policy must refuse an in-place retry rather than drop data.
            try {
                app(FreshInstallerService::class)->installFresh($installConfig);
                $this->fail('Installer retried against a partially migrated non-empty database.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('previous fresh installation was interrupted', $exception->getMessage());
                $this->assertStringContainsString('will not drop or truncate existing data', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($lockPath);
            $this->assertFileExists($lockPath . '.in-progress', 'The interrupted marker must remain until the operator provisions a fresh empty database.');
        } finally {
            \Illuminate\Support\Facades\Artisan::swap($consoleKernel);
            DB::disconnect('installer_seeder_failure_test');
            DB::setDefaultConnection($originalDefault);
            config([
                'database.default' => $originalDefault,
                'app.key' => $originalAppKey,
                'lms.plugin_signing_key' => $originalSigningKey,
                'lms.backup_encryption_key' => $originalBackupKey,
            ]);

            foreach ([$lockPath, $lockPath . '.mutex', $envPath] as $file) {
                @unlink($file);
            }
            foreach ([$privatePath, $backupPath] as $directory) {
                @rmdir($directory);
            }
            @rmdir($root);
        }
    }

    public function test_two_real_php_workers_serialize_fresh_installation_against_one_sqlite_database(): void
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            $this->markTestSkipped('The PHP process control extension is required for the installer concurrency regression.');
        }

        $root = sys_get_temp_dir() . '/aec-installer-concurrency-' . bin2hex(random_bytes(8));
        $lockDirectory = $root . '/locks';
        $privateDirectory = $root . '/private';
        $backupDirectory = $root . '/backups';
        $databasePath = $root . '/shared.sqlite';
        $envPath = $root . '/.env';
        $gatePath = $root . '/start.gate';
        $workerPath = base_path('tests/Support/fresh-installer-worker.php');
        mkdir($lockDirectory, 0700, true);
        file_put_contents($databasePath, '');
        file_put_contents($envPath, "APP_KEY=\n");

        $processes = [];
        $pipesByWorker = [];
        try {
            $this->assertFileExists($workerPath, 'The real PHP installer worker must be present.');
            // Pass only the minimal runtime environment; never inherit CI database
            // credentials or unrelated secrets into the child installer processes.
            $baseEnvironment = [
                'PATH' => (string) (getenv('PATH') ?: ''),
                'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
            ];

            for ($worker = 1; $worker <= 2; $worker++) {
                $workerEnvironment = array_merge($baseEnvironment, [
                    'APP_ENV' => 'testing',
                    'APP_DEBUG' => 'false',
                    'APP_URL' => 'https://aec-concurrent.test',
                    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                    'AEC_TEST_EXPLICIT_PLUGIN_BOOT' => '0',
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => $databasePath,
                    'CACHE_STORE' => 'file',
                    'CACHE_DRIVER' => 'file',
                    'SESSION_DRIVER' => 'file',
                    'QUEUE_CONNECTION' => 'sync',
                    'AEC_INSTALL_LOCK_PATH' => $lockDirectory . '/installed.lock',
                    'AEC_ENV_FILE_PATH' => $envPath,
                    'AEC_PRIVATE_STORAGE_PATH' => $privateDirectory,
                    'AEC_BACKUP_PATH' => $backupDirectory,
                    'AEC_INSTALLER_READY_FILE' => $root . '/worker-' . $worker . '.ready',
                    'AEC_INSTALLER_START_GATE' => $gatePath,
                ]);

                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $workerPath],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    base_path(),
                    $workerEnvironment
                );
                $this->assertIsResource($process, 'Unable to start a real PHP installer worker.');
                fclose($pipes[0]);
                $processes[] = $process;
                $pipesByWorker[] = $pipes;
            }

            $readyDeadline = microtime(true) + 15;
            while (microtime(true) < $readyDeadline) {
                if (is_file($root . '/worker-1.ready') && is_file($root . '/worker-2.ready')) {
                    break;
                }
                usleep(10000);
            }
            $this->assertFileExists($root . '/worker-1.ready', 'First PHP worker did not reach the shared start barrier.');
            $this->assertFileExists($root . '/worker-2.ready', 'Second PHP worker did not reach the shared start barrier.');

            // Release both independently bootstrapped PHP processes at the same instant.
            file_put_contents($gatePath, 'go', LOCK_EX);

            $outcomes = [];
            foreach ($processes as $index => $process) {
                $stdout = stream_get_contents($pipesByWorker[$index][1]);
                $stderr = stream_get_contents($pipesByWorker[$index][2]);
                fclose($pipesByWorker[$index][1]);
                fclose($pipesByWorker[$index][2]);
                $exitCode = proc_close($process);

                $decoded = json_decode(trim((string) $stdout), true);
                $this->assertSame(0, $exitCode, 'Installer worker exited abnormally: ' . (string) $stderr);
                $this->assertIsArray($decoded, 'Installer worker did not return JSON. stdout=' . (string) $stdout . ' stderr=' . (string) $stderr);
                $outcomes[] = $decoded;
            }

            $successful = array_values(array_filter($outcomes, static fn (array $outcome): bool => ($outcome['success'] ?? false) === true));
            $rejected = array_values(array_filter($outcomes, static fn (array $outcome): bool => ($outcome['success'] ?? true) === false));

            $this->assertCount(1, $successful, 'Exactly one real PHP worker must complete the fresh installation.');
            $this->assertCount(1, $rejected, 'The competing PHP worker must be rejected after observing the completed install lock.');
            $this->assertStringContainsString('already installed', strtolower((string) ($rejected[0]['message'] ?? '')));

            $pdo = new PDO('sqlite:' . $databasePath);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'concurrent-admin@aec.test'")->fetchColumn());
            $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            $this->assertFileExists($lockDirectory . '/installed.lock');
            $this->assertFileDoesNotExist($lockDirectory . '/installed.lock.in-progress');
            $this->assertFileExists($lockDirectory . '/installed.lock.mutex');
        } finally {
            foreach ($processes as $index => $process) {
                if (is_resource($process)) {
                    @proc_terminate($process);
                    if (isset($pipesByWorker[$index])) {
                        foreach ([1, 2] as $pipeIndex) {
                            if (isset($pipesByWorker[$index][$pipeIndex]) && is_resource($pipesByWorker[$index][$pipeIndex])) {
                                @fclose($pipesByWorker[$index][$pipeIndex]);
                            }
                        }
                    }
                    @proc_close($process);
                }
            }

            foreach (glob($root . '/*') ?: [] as $entry) {
                if (is_dir($entry) && !is_link($entry)) {
                    foreach (glob($entry . '/*') ?: [] as $child) {
                        if (is_file($child) || is_link($child)) {
                            @unlink($child);
                        }
                    }
                    @rmdir($entry);
                } else {
                    @unlink($entry);
                }
            }
            @unlink($envPath);
            @rmdir($root);
        }
    }

    public function test_fresh_installer_rejects_symlinked_lock_directory_and_mutex_file(): void
    {
        $targetDirectory = sys_get_temp_dir() . '/aec-installer-lock-target-' . uniqid('', true);
        $symlinkDirectory = sys_get_temp_dir() . '/aec-installer-lock-link-' . uniqid('', true);
        $mutexDirectory = sys_get_temp_dir() . '/aec-installer-mutex-dir-' . uniqid('', true);
        $mutexTarget = sys_get_temp_dir() . '/aec-installer-mutex-target-' . uniqid('', true);

        mkdir($targetDirectory, 0700, true);
        mkdir($mutexDirectory, 0700, true);
        file_put_contents($mutexTarget, 'do-not-modify');

        try {
            $this->assertTrue(symlink($targetDirectory, $symlinkDirectory));
            config(['lms.install_lock_path' => $symlinkDirectory . '/installed.lock']);

            try {
                app(FreshInstallerService::class)->installFresh([]);
                $this->fail('Installer accepted a symbolic-link lock directory.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('lock directory', $exception->getMessage());
            }

            $mutexPath = $mutexDirectory . '/installed.lock.mutex';
            $this->assertTrue(symlink($mutexTarget, $mutexPath));
            config(['lms.install_lock_path' => $mutexDirectory . '/installed.lock']);

            try {
                app(FreshInstallerService::class)->installFresh([]);
                $this->fail('Installer accepted a symbolic-link mutex file.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('symbolic link', $exception->getMessage());
            }

            $this->assertSame('do-not-modify', file_get_contents($mutexTarget));
        } finally {
            @unlink($symlinkDirectory);
            @unlink($mutexDirectory . '/installed.lock.mutex');
            @unlink($mutexTarget);
            @rmdir($targetDirectory);
            @rmdir($mutexDirectory);
            config(['lms.install_lock_path' => $this->tempLockPath]);
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
