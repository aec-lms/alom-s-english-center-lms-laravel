<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use App\Core\Contracts\PluginInterface;
use App\Core\Events\PluginLifecycleEvent;
use App\Core\Security\AuditLogger;
use App\Models\Permission;
use App\Models\PluginRecord;
use App\Models\PluginSetting;
use App\Models\Role;
use App\Models\UpdateLog;
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
        $coreVersion = (string) config('lms.core_version', '2.0.0');
        $major = explode('.', $coreVersion)[0] ?? '2';
        $constraint = ltrim($manifest->coreCompatibility, '^~>= ');
        $requiredMajor = explode('.', $constraint)[0] ?? $major;

        return $major === $requiredMajor;
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
        $fromVersion = $record?->version ?? '0.0.0';

        $this->runPluginMigrations($manifest);

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
            if (!$this->isCompatible($manifest)) {
                throw new RuntimeException("Plugin {$manifest->slug} is incompatible with Core " . config('lms.core_version'));
            }
            $this->assertDependenciesSatisfied($manifest, false);

            $targetDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $manifest->slug;
            $previousRecord = PluginRecord::query()->where('slug', $manifest->slug)->first();
            $alreadyInstalled = (bool) ($previousRecord?->is_installed);

            if (is_dir($targetDir)) {
                $rollbackDir = storage_path('app/plugin-rollbacks/' . $manifest->slug . '-' . uniqid('', true));
                File::ensureDirectoryExists(dirname($rollbackDir));
                File::copyDirectory($targetDir, $rollbackDir);
            }

            File::ensureDirectoryExists(dirname($targetDir));
            if (is_dir($targetDir)) {
                File::deleteDirectory($targetDir);
            }
            File::copyDirectory($packageRoot, $targetDir);

            try {
                $record = $alreadyInstalled
                    ? $this->updatePlugin($manifest->slug, $actorId)
                    : $this->install($manifest->slug, $actorId);

                if ($rollbackDir && is_dir($rollbackDir)) {
                    File::deleteDirectory($rollbackDir);
                }

                return $record;
            } catch (Throwable $e) {
                // Automatic rollback after failed install/update
                if ($targetDir && is_dir($targetDir)) {
                    File::deleteDirectory($targetDir);
                }
                if ($rollbackDir && is_dir($rollbackDir) && $targetDir) {
                    File::copyDirectory($rollbackDir, $targetDir);
                    File::deleteDirectory($rollbackDir);
                }
                if ($alreadyInstalled && $previousRecord) {
                    $previousRecord->save();
                }
                $this->logPluginOperation(
                    $manifest->slug,
                    $previousRecord?->version ?? '0.0.0',
                    $manifest->version,
                    'rolled_back',
                    'Automatic rollback triggered: ' . $e->getMessage(),
                    $actorId
                );
                $this->audit->log('plugin.rollback', 'plugin', $manifest->slug, ['error' => $e->getMessage()], $actorId);
                throw new RuntimeException("Plugin operation failed and was automatically rolled back: {$e->getMessage()}", 0, $e);
            }
        } finally {
            File::deleteDirectory($stagingDir);
        }
    }

    public function enable(string $slug, ?int $actorId = null): PluginRecord
    {
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
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
        event(new PluginLifecycleEvent('enabled', $slug, $record->version, $actorId));

        return $record->fresh();
    }

    public function disable(string $slug, ?int $actorId = null): PluginRecord
    {
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
        if ($record->is_core || $slug === config('plugins.core_identifier')) {
            throw new RuntimeException('Protected Core cannot be disabled.');
        }

        $this->assertNoActiveDependents($slug, 'disable');

        $record->update(['is_enabled' => false]);
        if (isset($this->discoverManifests()[$slug])) {
            $this->resolvePluginInstance($this->discoverManifests()[$slug])?->onDisable();
        }
        $this->audit->log('plugin.disabled', 'plugin', $slug, [], $actorId);
        event(new PluginLifecycleEvent('disabled', $slug, $record->version, $actorId));

        return $record->fresh();
    }

    /**
     * Uninstalls any plugin cleanly while strictly preserving business records
     * (students, teachers, payments, enrollments, audit logs).
     */
    public function uninstall(string $slug, bool $confirmedPurgePluginData = false, ?int $actorId = null): PluginRecord
    {
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
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
        event(new PluginLifecycleEvent('uninstalled', $slug, $record->version, $actorId));

        return $record->fresh();
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

    private function assertSafeZipArchive(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);

            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
                throw new RuntimeException("Unsafe ZIP path rejected: {$name}");
            }
            if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
                throw new RuntimeException("Absolute path rejected in ZIP: {$name}");
            }
            $segments = explode('/', $name);
            if (in_array('..', $segments, true)) {
                throw new RuntimeException("ZIP path traversal detected and blocked: {$name}");
            }

            if (is_array($stat) && isset($stat['external_attributes'])) {
                $mode = ($stat['external_attributes'] >> 16) & 0xF000;
                if ($mode === 0xA000) {
                    throw new RuntimeException("Symlink entry blocked in ZIP: {$name}");
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
            return;
        }

        $checksums = json_decode((string) file_get_contents($checksumFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($checksums)) {
            throw new RuntimeException('Invalid checksums.json format inside plugin package.');
        }

        foreach ($checksums as $relativePath => $expectedSha) {
            if (!is_string($relativePath) || !is_string($expectedSha) || str_contains($relativePath, '..')) {
                throw new RuntimeException('Unsafe path inside checksums.json manifest.');
            }
            $full = $packageRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
            if (!is_file($full)) {
                throw new RuntimeException("File integrity check failed: missing {$relativePath}");
            }
            if (!hash_equals(strtolower($expectedSha), (string) hash_file('sha256', $full))) {
                throw new RuntimeException("File integrity SHA-256 mismatch on {$relativePath}");
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

        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $migrationsDir);
        Artisan::call('migrate', ['--path' => $relative, '--force' => true]);

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
        if (!$manifest->providerClass || !class_exists($manifest->providerClass)) {
            return null;
        }
        $instance = app()->resolveProvider($manifest->providerClass);

        return $instance instanceof PluginInterface ? $instance : null;
    }
}
