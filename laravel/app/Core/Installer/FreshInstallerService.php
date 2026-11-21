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
use DateTimeZone;
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

        $privatePath = (string) config('lms.private_storage_path');
        $backupPath = (string) config('lms.backup_path');
        $directories = [
            'storage/app' => is_writable(storage_path('app')),
            'storage/framework' => is_writable(storage_path('framework')),
            'storage/logs' => is_writable(storage_path('logs')),
            'private_storage' => is_dir($privatePath) ? is_writable($privatePath) : is_writable(dirname($privatePath)),
            'backup_storage' => is_dir($backupPath) ? is_writable($backupPath) : is_writable(dirname($backupPath)),
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


    /**
     * Atomically persist installer-generated secrets without exposing them in logs or responses.
     */
    private function persistGeneratedKeys(array $keys): void
    {
        $envPath = (string) config('lms.env_path', base_path('.env'));
        if (!is_file($envPath) || !is_writable($envPath)) {
            throw new RuntimeException('The Laravel .env file must exist and be writable so fresh encryption keys can be saved securely.');
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the Laravel .env file.');
        }

        foreach ($keys as $name => $value) {
            $escaped = addcslashes((string) $value, "\\\"");
            $line = $name . '="' . $escaped . '"';
            $pattern = '/^' . preg_quote($name, '/') . '=.*/m';
            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
            }
        }

        $temporaryPath = tempnam(dirname($envPath), '.env-aec-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate a temporary environment file.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write generated encryption keys to the environment file.');
            }
            @chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $envPath)) {
                throw new RuntimeException('Unable to atomically replace the Laravel environment file.');
            }
            @chmod($envPath, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        config([
            'app.key' => $keys['APP_KEY'],
            'lms.plugin_signing_key' => $keys['AEC_PLUGIN_SIGNING_KEY'],
            'lms.backup_encryption_key' => $keys['AEC_BACKUP_ENCRYPTION_KEY'],
        ]);
    }



    private function preparePrivateStorageDirectories(): void
    {
        $privatePath = rtrim((string) config('lms.private_storage_path'), DIRECTORY_SEPARATOR);
        $backupPath = rtrim((string) config('lms.backup_path'), DIRECTORY_SEPARATOR);
        if ($privatePath === '' || $backupPath === '' || is_link($privatePath) || is_link($backupPath)) {
            throw new RuntimeException('Private storage and backup paths must be configured and must not be symbolic links.');
        }

        $realPublic = realpath(public_path()) ?: rtrim(public_path(), DIRECTORY_SEPARATOR);
        $publicPrefix = rtrim($realPublic, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ([$privatePath, $backupPath] as $directory) {
            $ancestor = $directory;
            while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            $realAncestor = realpath($ancestor);
            if ($directory === $realPublic
                || str_starts_with($directory, $publicPrefix)
                || $realAncestor === $realPublic
                || ($realAncestor !== false && str_starts_with($realAncestor, $publicPrefix))
            ) {
                throw new RuntimeException('Private storage and backups must be outside the public web document root.');
            }
        }

        foreach ([$privatePath, $backupPath] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create private runtime directory: {$directory}");
            }
            @chmod($directory, 0700);
            if (!is_writable($directory)) {
                throw new RuntimeException("Private runtime directory is not writable: {$directory}");
            }
        }

        $realPrivate = realpath($privatePath);
        $realBackup = realpath($backupPath);
        if ($realPrivate === false || $realBackup === false) {
            throw new RuntimeException('Unable to resolve private storage and backup directories.');
        }
        foreach ([$realPrivate, $realBackup] as $directory) {
            if ($directory === $realPublic || str_starts_with($directory, $publicPrefix)) {
                throw new RuntimeException('Private storage and backups must be outside the public web document root.');
            }
        }

        if ($realPrivate === $realBackup
            || str_starts_with($realPrivate, rtrim($realBackup, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || str_starts_with($realBackup, rtrim($realPrivate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Private uploads and backup archives must use separate directories.');
        }
    }

    private function callArtisanOrFail(string $command, array $parameters, string $description): void
    {
        $exitCode = Artisan::call($command, $parameters);
        if ($exitCode !== 0) {
            throw new RuntimeException($description . ' failed: ' . trim(Artisan::output()));
        }
    }

    /**
     * Serialize fresh installation across PHP workers on the same filesystem.
     *
     * flock() is released by the operating system when a worker exits or crashes,
     * so the persistent mutex inode is safe to reuse and cannot become a stale lock.
     */
    public function installFresh(array $config): array
    {
        $installLockPath = trim((string) config('lms.install_lock_path'));
        if ($installLockPath === '') {
            throw new RuntimeException('The installer lock path is not configured.');
        }

        $lockDirectory = dirname($installLockPath);
        if (is_link($lockDirectory)) {
            throw new RuntimeException('The installation lock directory must not be a symbolic link.');
        }
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0750, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Unable to create the installation lock directory.');
        }

        // Resolve the directory after creation: a symlink in any path component
        // must not silently redirect the mutex to an attacker-controlled location.
        $realLockDirectory = realpath($lockDirectory);
        if ($realLockDirectory === false || $realLockDirectory !== rtrim($lockDirectory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The installation lock directory path must resolve without symbolic-link redirection.');
        }

        $mutexPath = $installLockPath . '.mutex';
        if (is_link($mutexPath)) {
            throw new RuntimeException('The installation mutex must not be a symbolic link.');
        }

        $mutex = @fopen($mutexPath, 'c');
        if ($mutex === false) {
            throw new RuntimeException('Unable to open the installation mutex.');
        }

        try {
            // Verify the opened inode still matches the path and is a private regular
            // file. This also rejects hard-linked mutex files and symlink swap races.
            $openedStat = fstat($mutex);
            $pathStat = @lstat($mutexPath);
            if ($openedStat === false
                || $pathStat === false
                || (($openedStat['mode'] & 0170000) !== 0100000)
                || (($pathStat['mode'] & 0170000) !== 0100000)
                || $openedStat['dev'] !== $pathStat['dev']
                || $openedStat['ino'] !== $pathStat['ino']
                || ($openedStat['nlink'] ?? 1) !== 1
            ) {
                throw new RuntimeException('The installation mutex must be a private, regular, non-linked file.');
            }

            @chmod($mutexPath, 0600);
            if (!flock($mutex, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the exclusive installation mutex.');
            }

            // Revalidate both the path and directory after acquiring the lock. This
            // closes the window where an attacker swaps the mutex path while this
            // worker is blocked in flock().
            $lockedStat = fstat($mutex);
            $currentPathStat = @lstat($mutexPath);
            $currentLockDirectory = realpath($lockDirectory);
            if ($lockedStat === false
                || $currentPathStat === false
                || (($lockedStat['mode'] & 0170000) !== 0100000)
                || (($currentPathStat['mode'] & 0170000) !== 0100000)
                || $lockedStat['dev'] !== $currentPathStat['dev']
                || $lockedStat['ino'] !== $currentPathStat['ino']
                || ($lockedStat['nlink'] ?? 1) !== 1
                || $currentLockDirectory === false
                || $currentLockDirectory !== $realLockDirectory
            ) {
                throw new RuntimeException('The installation mutex or lock directory changed while acquiring the lock.');
            }

            // Another worker may have completed installation while this worker waited.
            if ($this->isInstalled()) {
                throw new RuntimeException('AEC LMS is already installed and locked.');
            }

            return $this->performInstallFresh($config);
        } finally {
            if (is_resource($mutex)) {
                @flock($mutex, LOCK_UN);
                fclose($mutex);
            }
        }
    }

    private function performInstallFresh(array $config): array
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('AEC LMS is already installed and locked.');
        }

        $appName = trim((string) ($config['app_name'] ?? 'AEC LMS'));
        $appUrl = trim((string) ($config['app_url'] ?? ''));
        $adminName = trim((string) ($config['admin_name'] ?? ''));
        $adminEmail = strtolower(trim((string) ($config['admin_email'] ?? '')));
        $adminPassword = (string) ($config['admin_password'] ?? '');
        $countryCode = strtoupper(trim((string) ($config['country_code'] ?? 'US')));
        $locale = trim((string) ($config['locale'] ?? 'en'));
        $timezone = trim((string) ($config['timezone'] ?? 'UTC'));
        $currency = strtoupper(trim((string) ($config['currency'] ?? 'USD')));

        if ($appName === '' || strlen($appName) > 160) {
            throw new RuntimeException('Application name is required and must be 160 characters or fewer.');
        }
        if ($adminName === '' || strlen($adminName) > 120) {
            throw new RuntimeException('Administrator name is required and must be 120 characters or fewer.');
        }
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false || strlen($adminEmail) > 180) {
            throw new RuntimeException('A valid administrator email address of 180 characters or fewer is required.');
        }
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 255) {
            throw new RuntimeException('Administrator password must be between 12 and 255 characters.');
        }
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new RuntimeException('Country must be a valid two-letter ISO 3166-1 alpha-2 code.');
        }
        if (!preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $locale)) {
            throw new RuntimeException('Please provide a valid locale identifier such as en, en-US or bn-BD.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) && $timezone !== 'UTC') {
            throw new RuntimeException('Please select a valid IANA timezone.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Currency must be a three-letter ISO 4217 code such as USD, EUR or BDT.');
        }
        $urlParts = parse_url($appUrl);
        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false
            || !is_array($urlParts)
            || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($urlParts['host'])
        ) {
            throw new RuntimeException('A valid HTTP or HTTPS application URL is required.');
        }

        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $dbName = (string) DB::connection()->getDatabaseName();

        $this->assertFreshEmptyDatabase($pdo, $dbName, $driver);

        $this->preparePrivateStorageDirectories();

        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $signingKey = bin2hex(random_bytes(32));
        $backupKey = base64_encode(random_bytes(32));

        // Persist fresh cryptographic keys before creating encrypted application data.
        $this->persistGeneratedKeys([
            'APP_KEY' => $appKey,
            'AEC_PLUGIN_SIGNING_KEY' => $signingKey,
            'AEC_BACKUP_ENCRYPTION_KEY' => $backupKey,
            'APP_TIMEZONE' => $timezone,
            'APP_LOCALE' => $locale,
            'APP_FALLBACK_LOCALE' => 'en',
        ]);
        config(['app.timezone' => $timezone, 'app.locale' => $locale]);

        $this->callArtisanOrFail('migrate', ['--force' => true], 'Core database migrations');

        $admin = DB::transaction(function () use ($adminName, $adminEmail, $adminPassword, $appName, $appUrl, $countryCode, $locale, $timezone, $currency): User {
            $this->callArtisanOrFail('db:seed', ['--class' => CoreRolesAndPermissionsSeeder::class, '--force' => true], 'Core roles and permissions seeding');

            $admin = User::query()->create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $adminRole = Role::query()->where('slug', 'admin')->first();
            if (!$adminRole) {
                throw new RuntimeException('Core Admin role was not created by the permissions seeder.');
            }
            $admin->roles()->sync([$adminRole->id]);

            SystemSetting::query()->updateOrCreate(['key' => 'site_name'], ['value' => $appName]);
            SystemSetting::query()->updateOrCreate(['key' => 'site_url'], ['value' => $appUrl]);
            SystemSetting::query()->updateOrCreate(['key' => 'institution.country_code'], ['value' => $countryCode]);
            SystemSetting::query()->updateOrCreate(['key' => 'localization.locale'], ['value' => $locale]);
            SystemSetting::query()->updateOrCreate(['key' => 'localization.timezone'], ['value' => $timezone]);
            SystemSetting::query()->updateOrCreate(['key' => 'finance.default_currency'], ['value' => $currency]);
            SystemSetting::query()->updateOrCreate(['key' => 'legacy_records_imported'], ['value' => 'false']);

            return $admin;
        });

        $lockPayload = json_encode([
            'installed_at' => now()->toIso8601String(),
            'core_version' => config('lms.core_version'),
            'legacy_records_imported' => false,
            'admin_user_id' => $admin->id,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $lockPath = (string) config('lms.install_lock_path');
        if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0750, true) && !is_dir(dirname($lockPath))) {
            throw new RuntimeException('Unable to create the installation lock directory.');
        }

        $temporaryLockPath = tempnam(dirname($lockPath), '.aec-installed-');
        if ($temporaryLockPath === false) {
            throw new RuntimeException('Unable to allocate the installation lock file.');
        }
        try {
            if (file_put_contents($temporaryLockPath, $lockPayload, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the installation lock file.');
            }
            @chmod($temporaryLockPath, 0600);
            if (!rename($temporaryLockPath, $lockPath)) {
                throw new RuntimeException('Unable to atomically create the installation lock file.');
            }
            @chmod($lockPath, 0600);
        } finally {
            if (is_file($temporaryLockPath)) {
                @unlink($temporaryLockPath);
            }
        }

        $this->audit->log('core.installed', 'system', 'fresh_install', ['legacy_records_imported' => false], $admin->id);

        // Never return generated encryption/signing secrets to the installer UI.
        return ['admin_id' => $admin->id, 'lock_path' => $lockPath];
    }
}
