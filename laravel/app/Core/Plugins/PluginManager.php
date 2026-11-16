<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use App\Core\Contracts\PluginInterface;
use App\Core\Events\PluginLifecycleEvent;
use App\Core\Security\AuditLogger;
use App\Core\Versioning\VersionConstraint;
use App\Models\Permission;
use App\Models\PluginRecord;
use App\Models\PluginSetting;
use App\Models\Role;
use App\Models\UpdateLog;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

class PluginManager
{
    private const BLOCKED_EXTENSIONS = ['phar', 'phtml', 'sh', 'bash', 'exe', 'bat', 'cmd', 'dll', 'so'];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, PluginManifest> */
    public function discoverManifests(): array
    {
        $modulesPath = (string) config('plugins.modules_path');
        if (!is_dir($modulesPath)) {
            return [];
        }

        $manifests = [];
        foreach (scandir($modulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $modulesPath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($dir) && is_file($dir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                $manifest = PluginManifest::fromDirectory($dir);
                $this->registerPluginAutoload($manifest);
                $manifests[$manifest->slug] = $manifest;
            }
        }
        ksort($manifests);

        return $manifests;
    }

    public function syncCatalog(): void
    {
        if (!Schema::hasTable('plugins')) {
            return;
        }

        foreach ($this->discoverManifests() as $manifest) {
            $existing = PluginRecord::query()->where('slug', $manifest->slug)->first();

            PluginRecord::query()->updateOrCreate(
                ['slug' => $manifest->slug],
                [
                    'name' => $manifest->name,
                    'version' => $existing && $existing->is_installed ? $existing->version : $manifest->version,
                    'author' => $manifest->author,
                    'category' => $manifest->category,
                    'description' => $manifest->description,
                    'core_compatibility' => $manifest->coreCompatibility,
                    'dependencies' => $manifest->dependencies,
                    'manifest_snapshot' => $manifest->toArray(),
                    'is_core' => $manifest->isCore || $manifest->slug === config('plugins.core_identifier'),
                ]
            );
        }
    }

    public function isCompatible(PluginManifest $manifest): bool
    {
        return VersionConstraint::matches(
            (string) config('lms.core_version', '2.0.0'),
            $manifest->coreCompatibility
        );
    }

    public function assertDependenciesSatisfied(PluginManifest $manifest, bool $requireEnabled = false): void
    {
        if ($manifest->dependencies === []) {
            return;
        }

        $catalog = $this->discoverManifests();
        foreach ($manifest->dependencies as $dependency) {
            if (!isset($catalog[$dependency])) {
                throw new RuntimeException("Plugin '{$manifest->slug}' requires missing dependency '{$dependency}'.");
            }
            $depRecord = PluginRecord::query()->where('slug', $dependency)->first();
            if (!$depRecord || !$depRecord->is_installed) {
                throw new RuntimeException("Plugin '{$manifest->slug}' requires installed dependency '{$dependency}'.");
            }
            if ($requireEnabled && !$depRecord->is_enabled) {
                throw new RuntimeException("Plugin '{$manifest->slug}' requires enabled dependency '{$dependency}'.");
            }
        }
    }

    public function install(string $slug, ?int $actorId = null): PluginRecord
    {
        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin {$slug} not found.");
        if (!$this->isCompatible($manifest)) {
            throw new RuntimeException("Plugin {$slug} ({$manifest->coreCompatibility}) is incompatible with Core " . config('lms.core_version'));
        }
        $this->assertDependenciesSatisfied($manifest, true);

        $migratedRelative = $this->runPluginMigrations($manifest);

        try {
            $record = DB::transaction(function () use ($manifest, $actorId): PluginRecord {
                $record = PluginRecord::query()->updateOrCreate(
                    ['slug' => $manifest->slug],
                    [
                        'name' => $manifest->name,
                        'version' => $manifest->version,
                        'author' => $manifest->author,
                        'category' => $manifest->category,
                        'description' => $manifest->description,
                        'core_compatibility' => $manifest->coreCompatibility,
                        'dependencies' => $manifest->dependencies,
                        'manifest_snapshot' => $manifest->toArray(),
                        'is_installed' => true,
                        'is_enabled' => true,
                        'installed_at' => now(),
                    ]
                );

                $this->syncManifestPermissions($manifest);

                $instance = $this->resolvePluginInstance($manifest);
                if ($instance) {
                    app()->register($instance);
                    $instance->onInstall();
                    $instance->onEnable();
                }

                $this->audit->log('plugin.installed', 'plugin', $manifest->slug, ['version' => $manifest->version], $actorId);

                return $record;
            });

            event(new PluginLifecycleEvent('installed', $manifest->slug, $manifest->version, $actorId));

            return $record;
        } catch (Throwable $e) {
            if ($migratedRelative !== null) {
                try {
                    Artisan::call('migrate:rollback', ['--path' => $migratedRelative, '--force' => true]);
                } catch (Throwable) {
                    // Best-effort rollback of plugin tables if safe
                }
            }
            $this->logPluginOperation($manifest->slug, '0.0.0', $manifest->version, 'rolled_back', 'Install rolled back: ' . $e->getMessage(), $actorId);
            throw $e;
        }
    }

    public function updatePlugin(string $slug, ?int $actorId = null): PluginRecord
    {
        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin {$slug} not found.");
        if (!$this->isCompatible($manifest)) {
            throw new RuntimeException("Plugin {$slug} ({$manifest->coreCompatibility}) is incompatible with Core " . config('lms.core_version'));
        }
        $this->assertDependenciesSatisfied($manifest, false);

        $record = PluginRecord::query()->where('slug', $slug)->first();
        if (!$record || !$record->is_installed) {
            throw new RuntimeException("Plugin {$slug} must be installed before it can be updated.");
        }

        $fromVersion = $record->version;
        if ($record && $record->is_installed && version_compare($manifest->version, $fromVersion, '<=')) {
            throw new RuntimeException('Plugin update version must be newer than the installed version.');
        }

        $migratedRelative = $this->runPluginMigrations($manifest);

        try {
            $updated = DB::transaction(function () use ($manifest, $fromVersion, $actorId): PluginRecord {
                $record = PluginRecord::query()->updateOrCreate(
                    ['slug' => $manifest->slug],
                    [
                        'name' => $manifest->name,
                        'version' => $manifest->version,
                        'author' => $manifest->author,
                        'category' => $manifest->category,
                        'description' => $manifest->description,
                        'core_compatibility' => $manifest->coreCompatibility,
                        'dependencies' => $manifest->dependencies,
                        'manifest_snapshot' => $manifest->toArray(),
                        'is_installed' => true,
                        'installed_at' => now(),
                    ]
                );

                $this->syncManifestPermissions($manifest);

                $this->logPluginOperation(
                    $manifest->slug,
                    $fromVersion,
                    $manifest->version,
                    'applied',
                    'Plugin updated and migrations synchronized from manifest.',
                    $actorId,
                    hash('sha256', json_encode($manifest->toArray(), JSON_THROW_ON_ERROR))
                );

                $this->audit->log('plugin.updated', 'plugin', $manifest->slug, [
                    'from_version' => $fromVersion,
                    'to_version' => $manifest->version,
                ], $actorId);

                return $record;
            });
        } catch (Throwable $e) {
            if ($migratedRelative !== null) {
                try {
                    Artisan::call('migrate:rollback', ['--path' => $migratedRelative, '--force' => true]);
                } catch (Throwable) {
                    // Preserve the original update exception; MySQL DDL may require manual recovery.
                }
            }
            throw $e;
        }

        event(new PluginLifecycleEvent('updated', $manifest->slug, $manifest->version, $actorId, ['from_version' => $fromVersion]));

        return $updated;
    }

    public function installOrUpdateFromZip(string $zipPath, ?int $actorId = null): PluginRecord
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Uploaded plugin archive could not be read.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open plugin ZIP archive.');
        }

        $stagingDir = storage_path('app/plugin-staging/' . uniqid('plg_', true));
        $rollbackDir = null;
        $targetDir = null;
        $previousRecord = null;
        File::ensureDirectoryExists($stagingDir);

        try {
            $this->assertSafeZipArchive($zip);
            $zip->extractTo($stagingDir);
            $zip->close();

            $manifestFile = $this->locateManifestFile($stagingDir);
            if (!$manifestFile) {
                throw new RuntimeException('Invalid plugin archive: plugin.json is missing.');
            }

            $packageRoot = dirname($manifestFile);
            $this->verifyPackageIntegrity($packageRoot);

            $manifest = PluginManifest::fromDirectory($packageRoot);
            if ($manifest->isCore || $manifest->slug === config('plugins.core_identifier')) {
                throw new RuntimeException('Core cannot be replaced through the plugin ZIP uploader.');
            }
            if (!$this->isCompatible($manifest)) {
                throw new RuntimeException("Plugin {$manifest->slug} is incompatible with Core " . config('lms.core_version'));
            }
            $this->assertDependenciesSatisfied($manifest, false);

            $targetDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $manifest->slug;
            $previousRecord = PluginRecord::query()->where('slug', $manifest->slug)->first();
            $previousAttributes = $previousRecord?->getAttributes();
            $fromVersion = $previousRecord?->version ?? '0.0.0';
            $alreadyInstalled = (bool) ($previousRecord?->is_installed);
            $targetReplaced = false;

            try {
                if (is_dir($targetDir)) {
                    $rollbackDir = storage_path('app/plugin-rollbacks/' . $manifest->slug . '-' . uniqid('', true));
                    File::ensureDirectoryExists(dirname($rollbackDir));
                    if (!File::copyDirectory($targetDir, $rollbackDir)) {
                        throw new RuntimeException('Unable to create a complete pre-update plugin snapshot.');
                    }
                }

                File::ensureDirectoryExists(dirname($targetDir));
                if (is_dir($targetDir)) {
                    File::deleteDirectory($targetDir);
                    $targetReplaced = true;
                }

                $targetReplaced = true;
                if (!File::copyDirectory($packageRoot, $targetDir)) {
                    throw new RuntimeException('Unable to copy the verified plugin package into its module directory.');
                }

                $record = $alreadyInstalled
                    ? $this->updatePlugin($manifest->slug, $actorId)
                    : $this->install($manifest->slug, $actorId);

                if ($rollbackDir && is_dir($rollbackDir)) {
                    File::deleteDirectory($rollbackDir);
                }

                return $record;
            } catch (Throwable $e) {
                $rollbackError = null;
                if ($targetReplaced && $targetDir && is_dir($targetDir)) {
                    File::deleteDirectory($targetDir);
                }
                if ($rollbackDir && is_dir($rollbackDir)) {
                    if ($targetReplaced && $targetDir) {
                        if (!File::copyDirectory($rollbackDir, $targetDir)) {
                            $rollbackError = ' Previous plugin files could not be restored from the snapshot.';
                        } else {
                            File::deleteDirectory($rollbackDir);
                        }
                    } else {
                        File::deleteDirectory($rollbackDir);
                    }
                }
                if ($alreadyInstalled && $previousRecord && is_array($previousAttributes)) {
                    unset($previousAttributes['id']);
                    $previousAttributes['updated_at'] = now();
                    PluginRecord::query()->whereKey($previousRecord->getKey())->update($previousAttributes);
                }

                $this->logPluginOperation(
                    $manifest->slug,
                    $fromVersion,
                    $manifest->version,
                    'rolled_back',
                    'Automatic rollback triggered: ' . $e->getMessage() . $rollbackError,
                    $actorId
                );
                $this->audit->log('plugin.rollback', 'plugin', $manifest->slug, [
                    'error' => $e->getMessage(),
                    'rollback_error' => $rollbackError,
                ], $actorId);
                throw new RuntimeException("Plugin operation failed and was automatically rolled back: {$e->getMessage()}{$rollbackError}", 0, $e);
            }
        } finally {
            File::deleteDirectory($stagingDir);
        }
    }

    public function enable(string $slug, ?int $actorId = null): PluginRecord
    {
        $record = DB::transaction(function () use ($slug, $actorId): PluginRecord {
            $record = PluginRecord::query()->where('slug', $slug)->lockForUpdate()->firstOrFail();
            if (!$record->is_installed) {
                throw new RuntimeException("Plugin {$slug} must be installed before enabling.");
            }

            $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin manifest {$slug} missing.");
            $this->assertDependenciesSatisfied($manifest, true);

            $record->update(['is_enabled' => true]);
            $provider = $this->resolvePluginInstance($manifest);
            if ($provider) {
                app()->register($provider);
                $provider->onEnable();
            }
            $this->audit->log('plugin.enabled', 'plugin', $slug, [], $actorId);

            return $record->fresh();
        });

        event(new PluginLifecycleEvent('enabled', $slug, $record->version, $actorId));

        return $record;
    }

    public function disable(string $slug, ?int $actorId = null): PluginRecord
    {
        $record = DB::transaction(function () use ($slug, $actorId): PluginRecord {
            $record = PluginRecord::query()->where('slug', $slug)->lockForUpdate()->firstOrFail();
            if ($record->is_core || $slug === config('plugins.core_identifier')) {
                throw new RuntimeException('Protected Core cannot be disabled.');
            }

            $this->assertNoActiveDependents($slug, 'disable');

            if (isset($this->discoverManifests()[$slug])) {
                $this->resolvePluginInstance($this->discoverManifests()[$slug])?->onDisable();
            }
            $record->update(['is_enabled' => false]);
            $this->audit->log('plugin.disabled', 'plugin', $slug, [], $actorId);

            return $record->fresh();
        });

        event(new PluginLifecycleEvent('disabled', $slug, $record->version, $actorId));

        return $record;
    }

    /**
     * Uninstalls any plugin cleanly while strictly preserving business records
     * (students, teachers, payments, enrollments, audit logs).
     */
    public function uninstall(string $slug, bool $confirmedPurgePluginData = false, ?int $actorId = null): PluginRecord
    {
        $record = DB::transaction(function () use ($slug, $confirmedPurgePluginData, $actorId): PluginRecord {
            $record = PluginRecord::query()->where('slug', $slug)->lockForUpdate()->firstOrFail();
            if ($record->is_core || $slug === config('plugins.core_identifier')) {
                throw new RuntimeException('Protected Core cannot be uninstalled.');
            }

            $this->assertNoActiveDependents($slug, 'uninstall');

            $manifest = $this->discoverManifests()[$slug] ?? null;
            if ($record->is_enabled && $manifest) {
                $this->resolvePluginInstance($manifest)?->onDisable();
            }

            if ($manifest) {
                $this->resolvePluginInstance($manifest)?->onUninstall($confirmedPurgePluginData);
            }

            $record->update(['is_installed' => false, 'is_enabled' => false]);
            $this->audit->log('plugin.uninstalled', 'plugin', $slug, ['purge_requested' => $confirmedPurgePluginData], $actorId);

            return $record->fresh();
        });

        event(new PluginLifecycleEvent('uninstalled', $slug, $record->version, $actorId));

        return $record;
    }

    public function saveSettings(string $slug, array $values, array $secretKeys = [], ?int $actorId = null): void
    {
        foreach ($values as $key => $val) {
            $isSecret = in_array($key, $secretKeys, true);
            $stringVal = is_scalar($val) ? (string) $val : json_encode($val, JSON_THROW_ON_ERROR);
            PluginSetting::query()->updateOrCreate(
                ['plugin_slug' => $slug, 'setting_key' => (string) $key],
                [
                    'setting_value' => $isSecret && $stringVal !== '' ? Crypt::encryptString($stringVal) : $stringVal,
                    'is_encrypted' => $isSecret,
                ]
            );
        }

        $this->audit->log('plugin.configured', 'plugin', $slug, ['keys' => array_keys($values)], $actorId);
    }

    public function getSetting(string $slug, string $key, ?string $default = null): ?string
    {
        if (!Schema::hasTable('plugin_settings')) {
            return $default;
        }

        $setting = PluginSetting::query()
            ->where('plugin_slug', $slug)
            ->where('setting_key', $key)
            ->first();

        return $setting ? ($setting->decryptedValue() ?? $default) : $default;
    }

    public function isEnabled(string $slug): bool
    {
        if (!Schema::hasTable('plugins')) {
            return false;
        }

        return PluginRecord::query()
            ->where('slug', $slug)
            ->where('is_installed', true)
            ->where('is_enabled', true)
            ->exists();
    }

    public function isInstalled(string $slug): bool
    {
        if (!Schema::hasTable('plugins')) {
            return false;
        }

        return PluginRecord::query()
            ->where('slug', $slug)
            ->where('is_installed', true)
            ->exists();
    }

    private function registerPluginAutoload(PluginManifest $manifest): void
    {
        $providerClass = $manifest->providerClass;
        if (!$providerClass) {
            return;
        }

        $providerMarker = '\\Providers\\';
        $position = strpos($providerClass, $providerMarker);
        if ($position === false) {
            return;
        }

        $namespacePrefix = substr($providerClass, 0, $position + 1);
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (!$loader instanceof ClassLoader) {
            return;
        }

        $existingPaths = $loader->getPrefixesPsr4()[$namespacePrefix] ?? [];
        if (!in_array($manifest->basePath, $existingPaths, true)) {
            $loader->addPsr4($namespacePrefix, $manifest->basePath);
        }
    }

    private function assertSafeZipArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > 5000) {
            throw new RuntimeException('Plugin archive contains too many entries.');
        }

        $seen = [];
        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);
            $normalized = str_replace('\\', '/', $name);
            $segments = explode('/', $normalized);

            if ($name === ''
                || str_contains($name, "\0")
                || str_contains($name, '\\')
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:#', $normalized) === 1
                || in_array('..', $segments, true)
                || in_array('.', $segments, true)
            ) {
                throw new RuntimeException("Unsafe ZIP path rejected: {$name}");
            }
            if (isset($seen[$name])) {
                throw new RuntimeException("Duplicate ZIP entry rejected: {$name}");
            }
            $seen[$name] = true;

            $size = (int) ($stat['size'] ?? 0);
            $totalBytes += $size;
            if ($size > 268435456 || $totalBytes > 1073741824) {
                throw new RuntimeException('Plugin archive exceeds the allowed uncompressed size.');
            }

            // ZipArchive::statIndex() does not expose Unix external attributes.
            // Read them through getExternalAttributesIndex() so symlink entries
            // cannot bypass the archive safety check and escape the staging tree.
            $opsys = 0;
            $externalAttributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $externalAttributes)
                && $opsys === ZipArchive::OPSYS_UNIX
            ) {
                $fileType = ($externalAttributes >> 16) & 0170000;
                if ($fileType === 0120000) {
                    throw new RuntimeException("Symlink entry blocked in ZIP: {$name}");
                }
                if ($fileType !== 0 && $fileType !== 0100000 && $fileType !== 0040000) {
                    throw new RuntimeException("Special file entry blocked in ZIP: {$name}");
                }
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                throw new RuntimeException("Disallowed executable extension '.{$ext}' rejected in ZIP.");
            }
        }
    }

    private function verifyPackageIntegrity(string $packageRoot): void
    {
        $checksumFile = $packageRoot . DIRECTORY_SEPARATOR . 'checksums.json';
        if (!is_file($checksumFile)) {
            throw new RuntimeException('Plugin package must include checksums.json.');
        }

        $checksums = json_decode((string) file_get_contents($checksumFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($checksums) || !isset($checksums['plugin.json'])) {
            throw new RuntimeException('checksums.json must include the plugin.json SHA-256.');
        }

        $verified = [];
        foreach ($checksums as $relativePath => $expectedSha) {
            if (!is_string($relativePath)
                || !is_string($expectedSha)
                || preg_match('/^[a-f0-9]{64}$/i', $expectedSha) !== 1
                || str_contains($relativePath, "\0")
                || str_contains($relativePath, '\\')
                || str_starts_with($relativePath, '/')
                || preg_match('#^[A-Za-z]:#', $relativePath) === 1
                || in_array('..', explode('/', $relativePath), true)
                || in_array('.', explode('/', $relativePath), true)
                || $relativePath === 'checksums.json'
            ) {
                throw new RuntimeException('Unsafe path or checksum inside checksums.json manifest.');
            }

            $full = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($full) || is_link($full)) {
                throw new RuntimeException("File integrity check failed: missing or unsafe {$relativePath}");
            }
            $actualSha = hash_file('sha256', $full);
            if (!is_string($actualSha) || !hash_equals(strtolower($expectedSha), strtolower($actualSha))) {
                throw new RuntimeException("File integrity SHA-256 mismatch on {$relativePath}");
            }
            $verified[$relativePath] = true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relativePath = ltrim(str_replace($packageRoot, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            if ($relativePath === 'checksums.json') {
                continue;
            }
            if (!isset($verified[str_replace(DIRECTORY_SEPARATOR, '/', $relativePath)])) {
                throw new RuntimeException("Plugin package contains an unverified file: {$relativePath}");
            }
        }
    }

    private function assertNoActiveDependents(string $slug, string $action): void
    {
        $enabledRecords = PluginRecord::query()
            ->where('slug', '!=', $slug)
            ->where('is_installed', true)
            ->where('is_enabled', true)
            ->get();

        foreach ($enabledRecords as $other) {
            $deps = (array) ($other->dependencies ?? []);
            if (in_array($slug, $deps, true)) {
                throw new RuntimeException("Cannot {$action} '{$slug}' while enabled plugin '{$other->slug}' depends on it.");
            }
        }
    }

    private function logPluginOperation(
        string $slug,
        string $fromVersion,
        string $toVersion,
        string $status,
        string $notes,
        ?int $actorId,
        ?string $checksum = null
    ): void {
        if (!Schema::hasTable('update_logs')) {
            return;
        }

        UpdateLog::query()->create([
            'target_type' => 'plugin',
            'target_slug' => $slug,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'package_checksum' => $checksum ?? hash('sha256', $slug . ':' . $toVersion . ':' . $status),
            'status' => $status,
            'notes' => $notes,
            'executed_by' => $actorId,
        ]);
    }

    private function runPluginMigrations(PluginManifest $manifest): ?string
    {
        $migrationsDir = $manifest->basePath . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (!is_dir($migrationsDir)) {
            return null;
        }

        $migrationFiles = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        if ($migrationFiles === []) {
            return null;
        }

        if (Schema::hasTable('migrations')) {
            $applied = DB::table('migrations')->pluck('migration')->all();
            $pending = array_filter($migrationFiles, static function (string $file) use ($applied): bool {
                return !in_array(pathinfo($file, PATHINFO_FILENAME), $applied, true);
            });
            if ($pending === []) {
                return null;
            }
        }

        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $migrationsDir);
        $exitCode = Artisan::call('migrate', ['--path' => $relative, '--force' => true]);
        if ($exitCode !== 0) {
            $migrationOutput = trim(Artisan::output());
            try {
                Artisan::call('migrate:rollback', ['--path' => $relative, '--force' => true]);
            } catch (Throwable) {
                // The original migration failure is more useful; MySQL DDL may need manual recovery.
            }
            throw new RuntimeException("Plugin '{$manifest->slug}' migrations failed: " . $migrationOutput);
        }

        return $relative;
    }

    private function syncManifestPermissions(PluginManifest $manifest): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->first();
        foreach ($manifest->permissions as $perm) {
            $permSlug = is_array($perm) ? (string) ($perm['slug'] ?? '') : (string) $perm;
            $permName = is_array($perm) ? (string) ($perm['name'] ?? $permSlug) : $permSlug;
            if ($permSlug === '') {
                continue;
            }
            $permission = Permission::query()->updateOrCreate(
                ['slug' => $permSlug],
                ['name' => $permName, 'module' => $manifest->slug]
            );
            if ($adminRole) {
                $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }

    private function locateManifestFile(string $dir): ?string
    {
        $direct = $dir . DIRECTORY_SEPARATOR . 'plugin.json';
        if (is_file($direct)) {
            return $direct;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $dir . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'plugin.json';
            if (is_dir($dir . DIRECTORY_SEPARATOR . $entry) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolvePluginInstance(PluginManifest $manifest): ?PluginInterface
    {
        if (!$manifest->providerClass) {
            return null;
        }
        $this->registerPluginAutoload($manifest);
        if (!class_exists($manifest->providerClass)) {
            throw new RuntimeException("Plugin provider class '{$manifest->providerClass}' could not be autoloaded.");
        }

        $instance = app()->resolveProvider($manifest->providerClass);
        if (!$instance instanceof PluginInterface) {
            throw new RuntimeException("Plugin provider '{$manifest->providerClass}' must implement PluginInterface.");
        }

        return $instance;
    }
}
