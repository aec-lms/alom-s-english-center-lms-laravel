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

            // The persistent mutex file is harmless; the kernel lock itself must
            // be released after success so a retry/next worker is never stranded.
            $mutexPath = $this->tempLockPath . '.mutex';
            $this->assertFileExists($mutexPath);
            $mutexHandle = fopen($mutexPath, 'c');
            $this->assertNotFalse($mutexHandle);
            try {
                $this->assertTrue(flock($mutexHandle, LOCK_EX | LOCK_NB));
                flock($mutexHandle, LOCK_UN);
            } finally {
                fclose($mutexHandle);
            }

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


    public function test_two_processes_cannot_run_fresh_install_concurrently(): void
    {
        if (!function_exists('proc_open') || !function_exists('flock')) {
            $this->markTestSkipped('Process creation and OS file locking are required for this regression.');
        }

        $root = sys_get_temp_dir() . '/aec-installer-race-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        $databasePath = $root . '/installer.sqlite';
        $installLockPath = $root . '/installed.lock';
        $privatePath = $root . '/private';
        $backupPath = $root . '/backups';
        $goPath = $root . '/go';
        $scriptPath = $root . '/worker.php';
        $envPath = base_path('.env');
        $hadEnv = is_file($envPath);
        $originalEnv = $hadEnv ? file_get_contents($envPath) : null;
        $originalMode = $hadEnv ? (fileperms($envPath) & 0777) : 0600;
        if (!$hadEnv) {
            file_put_contents($envPath, "APP_KEY=\n");
        }

        $worker = <<<'PHP'
<?php
declare(strict_types=1);

$base = $argv[1];
[$db, $installLock, $private, $backup, $ready, $go, $result] = array_slice($argv, 2);
chdir($base);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'installer_concurrency',
    'database.connections.installer_concurrency' => [
        'driver' => 'sqlite',
        'database' => $db,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],
    'lms.install_lock_path' => $installLock,
    'lms.private_storage_path' => $private,
    'lms.backup_path' => $backup,
]);
Illuminate\Support\Facades\DB::purge('installer_concurrency');

file_put_contents($ready, 'ready');
$deadline = microtime(true) + 20;
while (!is_file($go) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($go)) {
    file_put_contents($result, json_encode(['success' => false, 'error' => 'barrier timeout']));
    exit(0);
}

try {
    app(App\Core\Installer\FreshInstallerService::class)->installFresh([
        'app_name' => 'AEC Concurrent Installer Test',
        'app_url' => 'https://aec.test',
        'admin_name' => 'Concurrent Admin',
        'admin_email' => 'installer-race@aec.test',
        'admin_password' => 'ConcurrentStrongPassword!2026',
    ]);
    $payload = ['success' => true];
} catch (Throwable $exception) {
    $payload = ['success' => false, 'error' => $exception->getMessage()];
}
file_put_contents($result, json_encode($payload, JSON_THROW_ON_ERROR));
PHP;
        file_put_contents($scriptPath, $worker, LOCK_EX);

        $processes = [];
        try {
            $base = base_path();
            for ($index = 0; $index < 2; $index++) {
                $ready = $root . '/ready-' . $index;
                $result = $root . '/result-' . $index;
                $command = [
                    PHP_BINARY,
                    $scriptPath,
                    $base,
                    $databasePath,
                    $installLockPath,
                    $privatePath,
                    $backupPath,
                    $ready,
                    $goPath,
                    $result,
                ];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, $base);
                $this->assertIsResource($process, 'Unable to start concurrent installer worker.');
                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'pipes' => $pipes, 'result' => $result, 'ready' => $ready];
            }

            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                if (is_file($root . '/ready-0') && is_file($root . '/ready-1')) {
                    break;
                }
                usleep(10000);
            }
            $this->assertFileExists($root . '/ready-0');
            $this->assertFileExists($root . '/ready-1');
            file_put_contents($goPath, 'go');

            $results = [];
            foreach ($processes as $entry) {
                $stdout = stream_get_contents($entry['pipes'][1]);
                $stderr = stream_get_contents($entry['pipes'][2]);
                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                $exitCode = proc_close($entry['process']);
                $this->assertSame(0, $exitCode, 'Installer worker failed: ' . $stderr . $stdout);
                $this->assertFileExists($entry['result']);
                $results[] = json_decode((string) file_get_contents($entry['result']), true, 512, JSON_THROW_ON_ERROR);
            }

            $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['success'] === true)), 'Concurrent installer results: ' . json_encode($results));
            $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['success'] === false && str_contains((string) $result['error'], 'already installed and locked'))));
            $this->assertFileExists($installLockPath);

            $pdo = new PDO('sqlite:' . $databasePath);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'installer-race@aec.test'")->fetchColumn());
        } finally {
            foreach ($processes as $entry) {
                if (is_resource($entry['process'])) {
                    foreach ([1, 2] as $pipeIndex) {
                        if (isset($entry['pipes'][$pipeIndex]) && is_resource($entry['pipes'][$pipeIndex])) {
                            fclose($entry['pipes'][$pipeIndex]);
                        }
                    }
                    @proc_terminate($entry['process']);
                    @proc_close($entry['process']);
                }
            }

            if ($hadEnv && is_string($originalEnv)) {
                file_put_contents($envPath, $originalEnv, LOCK_EX);
                @chmod($envPath, $originalMode);
            } elseif (!$hadEnv) {
                @unlink($envPath);
            }

            \Illuminate\Support\Facades\File::deleteDirectory($root);
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
