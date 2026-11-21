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
use Illuminate\Validation\ValidationException;
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
                if ($manifest->slug !== $entry) {
                    throw new RuntimeException("Plugin folder '{$entry}' does not match manifest slug '{$manifest->slug}'.");
                }
                if (isset($manifests[$manifest->slug])) {
                    throw new RuntimeException("Duplicate plugin manifest slug '{$manifest->slug}' was discovered.");
                }
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

        $previousRecord = PluginRecord::query()->where('slug', $slug)->first();
        $previousAttributes = $previousRecord?->getAttributes();
        $wasInstalled = (bool) ($previousRecord?->is_installed);
        $wasEnabled = (bool) ($previousRecord?->is_enabled);
        $previousInstalledAt = $previousRecord?->installed_at;
        $migratedRelative = $this->runPluginMigrations($manifest);

        try {
            $record = DB::transaction(function () use ($manifest, $actorId, $wasInstalled, $wasEnabled, $previousInstalledAt): PluginRecord {
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
                        // Re-running install must not silently re-enable a plugin
                        // that an administrator deliberately disabled.
                        'is_enabled' => $wasInstalled ? $wasEnabled : true,
                        'installed_at' => $wasInstalled ? ($previousInstalledAt ?? now()) : now(),
                    ]
                );

                $this->syncManifestPermissions($manifest);

                $instance = $this->resolvePluginInstance($manifest);
                if ($instance) {
                    app()->register($instance);
                    if (!$wasInstalled) {
                        $instance->onInstall();
                        $instance->onEnable();
                    }
                }

                $this->audit->logRequired('plugin.installed', 'plugin', $manifest->slug, ['version' => $manifest->version], $actorId);

                return $record;
            });

            // Register plugin routes after the database transaction commits so the router
            // sees a stable installed/enabled plugin state in tests and long-lived workers.
            if (!$wasInstalled) {
                $this->loadPluginRoutes($manifest);
            }
        } catch (Throwable $e) {
            // Route registration happens after commit. If it fails, compensate the
            // committed catalog state before rolling back the plugin migrations.
            DB::transaction(function () use ($slug, $previousRecord, $previousAttributes): void {
                if ($previousRecord && is_array($previousAttributes)) {
                    PluginRecord::query()->whereKey($previousRecord->getKey())->update($previousAttributes);
                } else {
                    PluginRecord::query()->where('slug', $slug)->delete();
                }
            });

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

        event(new PluginLifecycleEvent('installed', $manifest->slug, $manifest->version, $actorId));

        return $record;
    }

    public function updatePlugin(string $slug, ?int $actorId = null, bool $skipExistingSignatureCheck = false): PluginRecord
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

        if (!$skipExistingSignatureCheck) {
            $this->assertSignedInstalledPackage($record);
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

                $this->audit->logRequired('plugin.updated', 'plugin', $manifest->slug, [
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

        $this->clearCachedPluginRoutes();
        event(new PluginLifecycleEvent('updated', $manifest->slug, $manifest->version, $actorId, ['from_version' => $fromVersion]));

        return $updated;
    }

    public function installOrUpdateFromZip(string $zipPath, ?int $actorId = null, ?string $signaturePath = null): PluginRecord
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

            $modulesRoot = realpath((string) config('plugins.modules_path'));
            if (!$modulesRoot || !is_dir($modulesRoot)) {
                throw new RuntimeException('Plugin modules directory is missing or unsafe.');
            }

            $targetDir = $modulesRoot . DIRECTORY_SEPARATOR . $manifest->slug;
            if (is_link($targetDir)) {
                throw new RuntimeException('Plugin update target cannot be a symbolic link.');
            }
            if (file_exists($targetDir)) {
                $resolvedTarget = realpath($targetDir);
                if (!$resolvedTarget || !is_dir($resolvedTarget) || dirname($resolvedTarget) !== $modulesRoot) {
                    throw new RuntimeException('Plugin update target is outside the registered modules directory.');
                }
            }

            // Preserve archive, checksum, manifest and target-path safety errors before
            // enforcing the trust decision, so unsigned packages cannot mask attacks.
            if ($signaturePath === null || !is_file($signaturePath)) {
                throw new RuntimeException('Unsigned plugin ZIP uploads are blocked. Provide a trusted Ed25519 detached signature.');
            }
            $signatureMetadata = app(PluginSignatureVerifier::class)->verify($packageRoot, $signaturePath);
            if (!copy($signaturePath, $packageRoot . DIRECTORY_SEPARATOR . '.aec-plugin-signature.json')) {
                throw new RuntimeException('Verified plugin signature could not be retained with the package.');
            }

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
                    // Treat the old target as replaced before deletion so a partial
                    // filesystem failure still enters the snapshot-restore path.
                    $targetReplaced = true;
                    if (!File::deleteDirectory($targetDir) || file_exists($targetDir)) {
                        throw new RuntimeException('Existing plugin package could not be safely removed before update.');
                    }
                }

                $targetReplaced = true;
                if (!File::copyDirectory($packageRoot, $targetDir)) {
                    throw new RuntimeException('Unable to copy the verified plugin package into its module directory.');
                }

                $record = $alreadyInstalled
                    ? $this->updatePlugin($manifest->slug, $actorId, true)
                    : $this->install($manifest->slug, $actorId);
                $record->forceFill([
                    'signature_key_id' => $signatureMetadata['key_id'],
                    'signature_algorithm' => $signatureMetadata['algorithm'],
                    'signature_checksums_sha256' => $signatureMetadata['checksums_sha256'],
                    'signature_verified_at' => $signatureMetadata['verified_at'],
                ])->save();
                $this->audit->logRequired('plugin.signature_verified', 'plugin', $manifest->slug, [
                    'key_id' => $signatureMetadata['key_id'],
                    'algorithm' => $signatureMetadata['algorithm'],
                    'checksums_sha256' => $signatureMetadata['checksums_sha256'],
                ], $actorId);

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
                if ($previousRecord && is_array($previousAttributes)) {
                    unset($previousAttributes['id']);
                    $previousAttributes['updated_at'] = now();
                    PluginRecord::query()->whereKey($previousRecord->getKey())->update($previousAttributes);
                } else {
                    PluginRecord::query()->where('slug', $manifest->slug)->delete();
                }

                $this->logPluginOperation(
                    $manifest->slug,
                    $fromVersion,
                    $manifest->version,
                    'rolled_back',
                    'Automatic rollback triggered: ' . $e->getMessage() . $rollbackError,
                    $actorId
                );
                $this->audit->logRequired('plugin.rollback', 'plugin', $manifest->slug, [
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
            $this->assertSignedInstalledPackage($record);
            if (!$record->is_installed) {
                throw new RuntimeException("Plugin {$slug} must be installed before enabling.");
            }

            $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin manifest {$slug} missing.");
            if (!$this->isCompatible($manifest)) {
                throw new RuntimeException("Plugin {$slug} ({$manifest->coreCompatibility}) is incompatible with Core " . config('lms.core_version'));
            }
            $this->assertDependenciesSatisfied($manifest, true);

            $record->update(['is_enabled' => true]);
            $provider = $this->resolvePluginInstance($manifest);
            if ($provider) {
                app()->register($provider);
                $provider->onEnable();
            }

            $this->audit->logRequired('plugin.enabled', 'plugin', $slug, [], $actorId);

            return $record->fresh();
        });

        $manifest = $this->discoverManifests()[$slug] ?? null;
        try {
            if ($manifest) {
                $this->loadPluginRoutes($manifest);
            }
        } catch (Throwable $exception) {
            // Route registration is deliberately post-commit so the router only sees
            // enabled plugins. If registration fails, compensate the catalog state:
            // never leave a plugin marked enabled while its routes are unavailable.
            DB::transaction(function () use ($slug, $actorId, $exception): void {
                $locked = PluginRecord::query()->where('slug', $slug)->lockForUpdate()->first();
                if ($locked && $locked->is_installed) {
                    $locked->update(['is_enabled' => false]);
                    $this->audit->logRequired('plugin.enable_rolled_back', 'plugin', $slug, [
                        'reason' => $exception->getMessage(),
                    ], $actorId);
                }
            });

            if ($manifest) {
                try {
                    $this->resolvePluginInstance($manifest)?->onDisable();
                } catch (Throwable $compensationError) {
                    report($compensationError);
                }
            }

            throw $exception;
        }

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
            $this->audit->logRequired('plugin.disabled', 'plugin', $slug, [], $actorId);

            return $record->fresh();
        });

        $this->clearCachedPluginRoutes();
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
            $this->audit->logRequired('plugin.uninstalled', 'plugin', $slug, ['purge_requested' => $confirmedPurgePluginData], $actorId);

            return $record->fresh();
        });

        $this->clearCachedPluginRoutes();
        event(new PluginLifecycleEvent('uninstalled', $slug, $record->version, $actorId));

        return $record;
    }

    /**
     * Move a plugin package to the private recycle bin without deleting its database records.
     * The Core plugin and plugins with active dependents are protected by lifecycle checks.
     */
    public function deleteToRecycleBin(string $slug, ?int $actorId = null): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $slug) !== 1
            || $slug === config('plugins.core_identifier')) {
            throw new RuntimeException('Invalid or protected plugin slug.');
        }

        $modulesPath = (string) config('plugins.modules_path');
        $modulesRoot = realpath($modulesPath);
        $sourceCandidate = rtrim($modulesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug;
        $source = realpath($sourceCandidate);
        if (!$modulesRoot || is_link($modulesPath) || is_link($sourceCandidate)
            || !$source || dirname($source) !== $modulesRoot
            || !is_dir($source) || !is_file($source . DIRECTORY_SEPARATOR . 'plugin.json')) {
            throw new RuntimeException('Plugin package is missing or outside the registered modules directory.');
        }

        $manifest = PluginManifest::fromDirectory($source);
        if ($manifest->isCore || $manifest->slug !== $slug) {
            throw new RuntimeException('Core or mismatched plugin packages cannot be deleted.');
        }

        $packageChecksums = $this->pluginDirectoryChecksums($source);
        $record = PluginRecord::query()->where('slug', $slug)->first();
        $wasInstalled = (bool) ($record?->is_installed);
        $wasEnabled = (bool) ($record?->is_enabled);

        // Validate the destination before changing the plugin's lifecycle state.
        $trashRoot = storage_path('app/plugin-recycle-bin');
        if (is_link($trashRoot)) {
            throw new RuntimeException('Plugin recycle-bin directory cannot be a symbolic link.');
        }
        File::ensureDirectoryExists($trashRoot);
        $resolvedTrashRoot = realpath($trashRoot);
        if (!$resolvedTrashRoot || !is_dir($resolvedTrashRoot)) {
            throw new RuntimeException('Plugin recycle-bin directory is missing or unsafe.');
        }
        $trashRoot = $resolvedTrashRoot;

        if ($wasInstalled) {
            $this->uninstall($slug, false, $actorId);
        }
        $token = now()->format('YmdHis') . '-' . bin2hex(random_bytes(5));
        $archiveName = $slug . '-' . $token;
        $destination = $trashRoot . DIRECTORY_SEPARATOR . $archiveName;
        if (!File::moveDirectory($source, $destination)) {
            if ($wasInstalled) {
                try {
                    $this->install($slug, $actorId);
                    if (!$wasEnabled) {
                        $this->disable($slug, $actorId);
                    }
                } catch (Throwable) {
                    PluginRecord::query()->where('slug', $slug)->update([
                        'is_installed' => false,
                        'is_enabled' => false,
                    ]);
                }
            }
            throw new RuntimeException('Could not move plugin package into the recycle bin. The previous installation state was restored where possible; database records remain preserved.');
        }

        if ($packageChecksums !== $this->pluginDirectoryChecksums($destination)) {
            $restored = File::moveDirectory($destination, $source);
            if ($restored && $wasInstalled) {
                try {
                    $this->install($slug, $actorId);
                    if (!$wasEnabled) {
                        $this->disable($slug, $actorId);
                    }
                } catch (Throwable) {
                    PluginRecord::query()->where('slug', $slug)->update([
                        'is_installed' => false,
                        'is_enabled' => false,
                    ]);
                }
            }
            throw new RuntimeException('Plugin recycle-bin archive integrity verification failed; the original package was restored where possible.');
        }

        $metadata = [
            'slug' => $slug,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'archive' => $archiveName,
            'was_installed' => $wasInstalled,
            'was_enabled' => $wasEnabled,
            'deleted_at' => now()->toIso8601String(),
            'files_sha256' => $packageChecksums,
        ];
        $metadataPath = $trashRoot . DIRECTORY_SEPARATOR . $archiveName . '.json';
        if (file_exists($metadataPath) || is_link($metadataPath)) {
            File::moveDirectory($destination, $source);
            throw new RuntimeException('Recycle-bin metadata destination already exists; the plugin package was returned.');
        }
        $written = File::put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($written === false) {
            File::moveDirectory($destination, $source);
            if ($wasInstalled) {
                try {
                    $this->install($slug, $actorId);
                    if (!$wasEnabled) {
                        $this->disable($slug, $actorId);
                    }
                } catch (Throwable) {
                    PluginRecord::query()->where('slug', $slug)->update(['is_installed' => false, 'is_enabled' => false]);
                }
            }
            throw new RuntimeException('Plugin recycle-bin metadata could not be written; the package was returned to its original location.');
        }
        @chmod($metadataPath, 0600);
        $this->audit->logRequired('plugin.deleted_to_recycle_bin', 'plugin', $slug, [
            'archive' => $archiveName,
            'business_data_preserved' => true,
        ], $actorId);

        return $metadata;
    }

    /** @return array<int, array<string, mixed>> */
    public function recycledPlugins(): array
    {
        $trashRoot = storage_path('app/plugin-recycle-bin');
        if (is_link($trashRoot) || !is_dir($trashRoot)) {
            return [];
        }

        $items = [];
        foreach (File::files($trashRoot) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            try {
                $metadata = json_decode(File::get($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($metadata) && isset($metadata['slug'], $metadata['archive'])) {
                    $items[] = $metadata;
                }
            } catch (Throwable) {
                // Ignore malformed metadata; do not expose unverified archive entries.
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) ($b['deleted_at'] ?? ''), (string) ($a['deleted_at'] ?? '')));
        return $items;
    }

    public function restoreFromRecycleBin(string $archiveName, ?int $actorId = null): PluginRecord|null
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,79}-[0-9]{14}-[a-f0-9]{10}$/', $archiveName) !== 1) {
            throw new RuntimeException('Invalid recycle-bin archive identifier.');
        }

        $trashRootCandidate = storage_path('app/plugin-recycle-bin');
        $trashRoot = realpath($trashRootCandidate);
        $metadataPath = $trashRootCandidate . DIRECTORY_SEPARATOR . $archiveName . '.json';
        $sourceCandidate = $trashRootCandidate . DIRECTORY_SEPARATOR . $archiveName;
        $metadataRealPath = realpath($metadataPath);
        $source = realpath($sourceCandidate);
        if (!$trashRoot || is_link($trashRootCandidate)
            || is_link($metadataPath) || !$metadataRealPath || dirname($metadataRealPath) !== $trashRoot
            || is_link($sourceCandidate) || !$source || dirname($source) !== $trashRoot || !is_dir($source)) {
            throw new RuntimeException('Recycled plugin package is missing or outside the protected recycle-bin directory.');
        }

        $metadata = json_decode(File::get($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $slug = (string) ($metadata['slug'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $slug) !== 1
            || ($metadata['archive'] ?? null) !== $archiveName
            || !is_file($source . DIRECTORY_SEPARATOR . 'plugin.json')
            || !is_array($metadata['files_sha256'] ?? null)
            || $metadata['files_sha256'] !== $this->pluginDirectoryChecksums($source)) {
            throw new RuntimeException('Recycle-bin package integrity check failed.');
        }

        $manifest = PluginManifest::fromDirectory($source);
        if ($manifest->slug !== $slug || $manifest->isCore || !$this->isCompatible($manifest)) {
            throw new RuntimeException('Recycled plugin is incompatible or its manifest identity is invalid.');
        }

        $modulesPath = (string) config('plugins.modules_path');
        $modulesRoot = realpath($modulesPath);
        if (!$modulesRoot || is_link($modulesPath)) {
            throw new RuntimeException('Plugin modules directory is missing or unsafe.');
        }
        $target = $modulesRoot . DIRECTORY_SEPARATOR . $slug;
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('A plugin package with this slug already exists. Remove or update it before restoring.');
        }
        File::ensureDirectoryExists(dirname($target));
        if (!File::moveDirectory($source, $target)) {
            throw new RuntimeException('Could not restore plugin package files.');
        }

        try {
            $this->registerPluginAutoload(PluginManifest::fromDirectory($target));
            $record = null;
            if ((bool) ($metadata['was_installed'] ?? false)) {
                $record = $this->install($slug, $actorId);
                if (!(bool) ($metadata['was_enabled'] ?? false)) {
                    $this->disable($slug, $actorId);
                }
            }
            // Keep the recovery metadata until the required audit event is durable.
            // If audit storage fails, the catch block can return the package to the
            // recycle bin and the original metadata remains discoverable.
            $this->audit->logRequired('plugin.restored_from_recycle_bin', 'plugin', $slug, [
                'archive' => $archiveName,
                'business_data_preserved' => true,
            ], $actorId);
            if (!@unlink($metadataPath)) {
                throw new RuntimeException('Plugin was restored and audited, but recycle-bin metadata could not be removed; manual reconciliation is required.');
            }
            return $record;
        } catch (Throwable $e) {
            // If installation succeeded but restoring the previous enabled state failed,
            // return the database lifecycle state to uninstalled before moving files back.
            if ((bool) ($metadata['was_installed'] ?? false) && $this->isInstalled($slug)) {
                try {
                    $this->uninstall($slug, false, $actorId);
                } catch (Throwable) {
                    PluginRecord::query()->where('slug', $slug)->update([
                        'is_installed' => false,
                        'is_enabled' => false,
                    ]);
                }
            }
            if (is_dir($target)) {
                File::moveDirectory($target, $source);
            }
            throw $e;
        }
    }

    public function permanentlyDeleteFromRecycleBin(string $archiveName, ?int $actorId = null): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,79}-[0-9]{14}-[a-f0-9]{10}$/', $archiveName) !== 1) {
            throw new RuntimeException('Invalid recycle-bin archive identifier.');
        }
        $trashRootCandidate = storage_path('app/plugin-recycle-bin');
        $trashRoot = realpath($trashRootCandidate);
        $metadataPath = $trashRootCandidate . DIRECTORY_SEPARATOR . $archiveName . '.json';
        $sourceCandidate = $trashRootCandidate . DIRECTORY_SEPARATOR . $archiveName;
        $metadataRealPath = realpath($metadataPath);
        $source = realpath($sourceCandidate);
        if (!$trashRoot || is_link($trashRootCandidate)
            || is_link($metadataPath) || !$metadataRealPath || dirname($metadataRealPath) !== $trashRoot
            || is_link($sourceCandidate) || !$source || dirname($source) !== $trashRoot || !is_dir($source)) {
            throw new RuntimeException('Recycled plugin package is missing or outside the protected recycle-bin directory.');
        }
        $metadata = json_decode(File::get($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $slug = (string) ($metadata['slug'] ?? '');
        $sourceChecksums = $this->pluginDirectoryChecksums($source);
        if (($metadata['archive'] ?? null) !== $archiveName
            || preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $slug) !== 1
            || !is_array($metadata['files_sha256'] ?? null)
            || $metadata['files_sha256'] !== $sourceChecksums) {
            throw new RuntimeException('Recycle-bin metadata or package integrity is invalid; permanent deletion was cancelled.');
        }

        // Keep a private, integrity-checked recovery snapshot before permanent removal.
        $backupRoot = storage_path('app/plugin-permanent-delete-backups');
        if (is_link($backupRoot)) {
            throw new RuntimeException('Permanent-delete recovery directory cannot be a symbolic link.');
        }
        File::ensureDirectoryExists($backupRoot);
        $resolvedBackupRoot = realpath($backupRoot);
        if (!$resolvedBackupRoot || !is_dir($resolvedBackupRoot)) {
            throw new RuntimeException('Permanent-delete recovery directory is missing or unsafe.');
        }
        @chmod($resolvedBackupRoot, 0700);
        $backupPath = $resolvedBackupRoot . DIRECTORY_SEPARATOR . $archiveName;
        if (is_link($backupPath) || (file_exists($backupPath) && !is_dir($backupPath))) {
            throw new RuntimeException('Permanent-delete recovery snapshot path is unsafe.');
        }
        if (is_dir($backupPath)) {
            // A previous attempt may have created the recovery copy but failed
            // before deleting the recycle-bin source. Reuse only an exact match.
            if ($sourceChecksums !== $this->pluginDirectoryChecksums($backupPath)) {
                throw new RuntimeException('An existing private recovery snapshot does not match the archive; permanent deletion was cancelled.');
            }
        } else {
            if (!File::copyDirectory($source, $backupPath)) {
                File::deleteDirectory($backupPath);
                throw new RuntimeException('Permanent delete cancelled: could not create a private backup.');
            }
            @chmod($backupPath, 0700);
            if ($sourceChecksums !== $this->pluginDirectoryChecksums($backupPath)) {
                File::deleteDirectory($backupPath);
                throw new RuntimeException('Permanent delete cancelled: backup integrity verification failed.');
            }
        }

        if (!File::deleteDirectory($source)) {
            throw new RuntimeException('Private backup was created, but the recycle-bin package could not be removed.');
        }
        @unlink($metadataPath);
        $this->audit->logRequired('plugin.permanently_deleted', 'plugin', $slug, [
            'package_files_removed_from_recycle_bin' => true,
            'private_backup_id' => $archiveName,
            'database_records_preserved' => true,
        ], $actorId);
    }

    /** @return array<string, string> */
    private function pluginDirectoryChecksums(string $directory): array
    {
        $root = realpath($directory);
        if (!$root || !is_dir($root) || is_link($directory)) {
            throw new RuntimeException('Archived plugin package directory is missing or unsafe.');
        }

        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Symlinks are not allowed in archived plugin packages.');
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile()) {
                throw new RuntimeException('Archived plugin package contains an unsupported filesystem entry.');
            }
            $path = $entry->getPathname();
            $resolved = realpath($path);
            if (!$resolved || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Archived plugin package contains a path outside its protected directory.');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
            $checksum = hash_file('sha256', $resolved);
            if (!is_string($checksum) || $checksum === '') {
                throw new RuntimeException('Could not verify an archived plugin package file checksum.');
            }
            $checksums[$relative] = $checksum;
        }
        ksort($checksums);

        return $checksums;
    }

    public function saveSettings(string $slug, array $values, array $secretKeys = [], ?int $actorId = null): void
    {
        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Unknown plugin: {$slug}");
        $schemaByKey = [];

        foreach ($manifest->settingsSchema as $field) {
            if (!is_array($field) || !isset($field['key']) || !is_string($field['key']) || trim($field['key']) === '') {
                continue;
            }

            $key = $field['key'];
            if (isset($schemaByKey[$key])) {
                throw new RuntimeException("Plugin {$slug} declares duplicate settings key: {$key}");
            }
            $schemaByKey[$key] = $field;
        }

        $validated = [];
        $manifestSecretKeys = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $field = $schemaByKey[$key] ?? null;
            if ($field === null) {
                throw ValidationException::withMessages([
                    "settings.{$key}" => "The {$key} setting is not declared by the {$slug} plugin manifest.",
                ]);
            }

            if (is_array($value) || is_object($value) || is_resource($value)) {
                throw ValidationException::withMessages([
                    "settings.{$key}" => "The {$key} setting must be a scalar value.",
                ]);
            }

            if (array_key_exists('options', $field) && is_array($field['options'])) {
                $optionKeys = array_map('strval', array_keys($field['options']));
                $optionValues = array_map(static fn ($option): string => is_scalar($option) ? (string) $option : '', array_values($field['options']));
                $allowedOptions = array_unique(array_merge($optionKeys, $optionValues));
                if (!in_array((string) $value, $allowedOptions, true)) {
                    throw ValidationException::withMessages([
                        "settings.{$key}" => "The selected value for {$key} is not allowed by the plugin manifest.",
                    ]);
                }
            }

            if ((bool) ($field['secret'] ?? false)
                || (bool) ($field['encrypted'] ?? false)
                || strtolower((string) ($field['type'] ?? '')) === 'password') {
                $manifestSecretKeys[] = $key;
            }

            $validated[$key] = $value;
        }

        DB::transaction(function () use ($validated, $secretKeys, $manifestSecretKeys, $slug, $actorId): void {
            foreach ($validated as $key => $val) {
                $isSecret = in_array($key, $secretKeys, true) || in_array($key, $manifestSecretKeys, true);
                $stringVal = is_scalar($val) ? (string) $val : json_encode($val, JSON_THROW_ON_ERROR);
                PluginSetting::query()->updateOrCreate(
                    ['plugin_slug' => $slug, 'setting_key' => (string) $key],
                    [
                        'setting_value' => $isSecret && $stringVal !== '' ? Crypt::encryptString($stringVal) : $stringVal,
                        'is_encrypted' => $isSecret && $stringVal !== '',
                    ]
                );
            }

            $this->audit->logRequired('plugin.configured', 'plugin', $slug, ['keys' => array_keys($validated)], $actorId);
        });
    }

    /** Reset only plugin configuration to manifest defaults; business data is never touched. */
    public function resetSettingsToDefaults(string $slug, ?int $actorId = null): void
    {
        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Unknown plugin: {$slug}");
        if (!Schema::hasTable('plugin_settings')) {
            throw new RuntimeException('Plugin settings storage is not available.');
        }

        DB::transaction(function () use ($slug, $manifest, $actorId): void {
            $existingSettings = PluginSetting::query()->where('plugin_slug', $slug)->get();
            $secretKeys = [];
            foreach ($manifest->settingsSchema as $field) {
                if ((bool) ($field['secret'] ?? $field['encrypted'] ?? false)) {
                    $secretKeys[] = (string) ($field['key'] ?? '');
                }
            }

            // Back up raw settings before reset. Secret values are ciphertext at rest;
            // encrypt any legacy plaintext secret before writing the private backup.
            $backupRows = $existingSettings->map(function (PluginSetting $setting) use ($secretKeys): array {
                $row = $setting->getAttributes();
                if (in_array((string) $setting->setting_key, $secretKeys, true)
                    && !(bool) $setting->is_encrypted
                    && (string) $setting->setting_value !== '') {
                    $row['setting_value'] = Crypt::encryptString((string) $setting->setting_value);
                    $row['is_encrypted'] = true;
                }
                return $row;
            })->all();

            $backupId = $slug . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(5));
            $backupRoot = storage_path('app/plugin-setting-backups');
            File::ensureDirectoryExists($backupRoot);
            @chmod($backupRoot, 0700);
            $backupPath = $backupRoot . DIRECTORY_SEPARATOR . $backupId . '.json';
            File::put($backupPath, json_encode([
                'plugin_slug' => $slug,
                'created_at' => now()->toIso8601String(),
                'settings' => $backupRows,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            @chmod($backupPath, 0600);

            PluginSetting::query()->where('plugin_slug', $slug)->delete();

            $values = [];
            foreach ($manifest->settingsSchema as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $default = $field['default'] ?? '';
                $values[$key] = is_scalar($default) ? (string) $default : json_encode($default, JSON_THROW_ON_ERROR);
                if ((bool) ($field['secret'] ?? $field['encrypted'] ?? false)) {
                    $secretKeys[] = $key;
                }
            }

            $this->saveSettings($slug, $values, $secretKeys, $actorId);
            $this->audit->logRequired('plugin.settings_reset', 'plugin', $slug, [
                'setting_count' => count($values),
                'backup_id' => $backupId,
                'business_data_preserved' => true,
            ], $actorId);
        });
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


    private function assertSignedInstalledPackage(PluginRecord $record): void
    {
        if (!$record->signature_key_id) {
            return; // First-party source modules installed from the shipped codebase.
        }

        $modulesRoot = realpath((string) config('plugins.modules_path'));
        $packageRoot = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $record->slug;
        $resolvedPackage = realpath($packageRoot);
        if (!$modulesRoot || !$resolvedPackage || dirname($resolvedPackage) !== $modulesRoot || is_link($packageRoot)) {
            throw new RuntimeException('Signed plugin package is missing or outside the registered modules directory.');
        }

        $this->verifyPackageIntegrity($resolvedPackage);
        $signaturePath = $resolvedPackage . DIRECTORY_SEPARATOR . '.aec-plugin-signature.json';
        $metadata = app(PluginSignatureVerifier::class)->verify($resolvedPackage, $signaturePath);
        if (!hash_equals((string) $record->signature_key_id, $metadata['key_id'])
            || !hash_equals((string) $record->signature_checksums_sha256, $metadata['checksums_sha256'])) {
            throw new RuntimeException('Installed plugin signature metadata no longer matches the trusted package record.');
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
            if (in_array($relativePath, ['checksums.json', '.aec-plugin-signature.json'], true)) {
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

    /**
     * Ensure a plugin installed/enabled after the application booted exposes its
     * routes to the next request in this process (including test and admin flows).
     * Route files are required again after runtime installation so newly enabled
     * modules are available without restarting the application.
     */
    private function clearCachedPluginRoutes(): void
    {
        $cachePath = app()->getCachedRoutesPath();
        if (!is_file($cachePath)) {
            return;
        }

        Artisan::call('route:clear');
        if (is_file($cachePath)) {
            throw new RuntimeException('Compiled plugin routes could not be invalidated after a plugin lifecycle change.');
        }
    }

    public function loadPluginRoutes(PluginManifest $manifest): void
    {
        // A compiled route cache already contains the deployment's plugin routes.
        // Runtime requires would bypass that cache and can create duplicate routes.
        $this->clearCachedPluginRoutes();

        foreach (['Routes/web.php', 'Routes/api.php'] as $relative) {
            $routeFile = rtrim($manifest->basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (is_file($routeFile)) {
                require $routeFile;
                app('router')->getRoutes()->refreshNameLookups();
            }
        }
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
