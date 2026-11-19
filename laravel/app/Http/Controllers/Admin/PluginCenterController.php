<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Plugins\PluginCategoryRegistry;
use App\Core\Plugins\PluginIconRegistry;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\PluginRecord;
use App\Models\PluginArchive;
use App\Models\PluginSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Plugins\Modules\GatewayStripe\Services\StripeGatewayService;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PluginCenterController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index(): View
    {
        $this->plugins->syncCatalog();
        $manifests = $this->plugins->discoverManifests();
        $recentLogs = \Illuminate\Support\Facades\Schema::hasTable('update_logs')
            ? \App\Models\UpdateLog::query()->where('target_type', 'plugin')->latest()->limit(100)->get()->groupBy('target_slug')
            : collect();

        $records = PluginRecord::query()->orderBy('category')->orderBy('name')->get()->map(function (PluginRecord $record) use ($manifests, $recentLogs) {
            $manifest = $manifests[$record->slug] ?? null;
            $record->setAttribute('icon', $manifest?->icon ?? PluginIconRegistry::resolve($record->slug, $record->category));
            $categoryKey = PluginCategoryRegistry::keyFor((string) $record->slug, (string) $record->category);
            $record->setAttribute('category_key', $categoryKey);
            $record->setAttribute('category_label', PluginCategoryRegistry::labels()[$categoryKey]);
            $record->setAttribute('is_compatible', $manifest ? $this->plugins->isCompatible($manifest) : false);
            $record->setAttribute('has_settings', $manifest && count($manifest->settingsSchema) > 0);
            $record->setAttribute('manifest_version', $manifest?->version ?? $record->version);
            $record->setAttribute('update_available', $manifest && version_compare($manifest->version, $record->version, '>'));
            $record->setAttribute('recent_logs', $recentLogs->get($record->slug, collect())->take(3));
            return $record;
        })->sortBy(fn (PluginRecord $record) => $record->category_label . '|' . $record->name)->values();

        $archives = Schema::hasTable('plugin_archives') ? PluginArchive::query()->latest('deleted_at')->get() : collect();

        return view('admin.plugins.index', compact('records', 'archives'));
    }

    public function favicon(string $slug): Response
    {
        abort_unless(preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1, 404);

        $manifest = $this->plugins->discoverManifests()[$slug] ?? null;
        abort_unless($manifest !== null, 404);

        return response(\App\Core\Plugins\PluginIconRegistry::faviconSvg($manifest->icon), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'plugin_zip' => ['required', 'file', 'mimes:zip', 'max:51200'],
            'plugin_signature' => ['required', 'file', 'mimes:json,txt', 'max:64'],
        ]);

        $stored = $request->file('plugin_zip')->store('plugin-uploads');
        $signatureStored = $request->file('plugin_signature')->store('plugin-uploads');
        $absolutePath = storage_path('app/' . $stored);
        $signaturePath = storage_path('app/' . $signatureStored);
        try {
            $record = $this->plugins->installOrUpdateFromZip($absolutePath, auth()->id(), $signaturePath);
            return back()->with('status', "Plugin '{$record->name}' (v{$record->version}) installed/updated successfully.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        } finally {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            if (is_file($signaturePath)) {
                @unlink($signaturePath);
            }
        }
    }

    public function install(string $slug): RedirectResponse
    {
        try {
            $this->plugins->install($slug, auth()->id());
            return back()->with('status', "Plugin '{$slug}' installed and enabled successfully.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function update(string $slug): RedirectResponse
    {
        try {
            $record = $this->plugins->updatePlugin($slug, auth()->id());
            return back()->with('status', "Plugin '{$record->name}' updated to v{$record->version}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function enable(string $slug): RedirectResponse
    {
        try {
            $this->plugins->enable($slug, auth()->id());
            return back()->with('status', "Plugin '{$slug}' enabled.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function disable(string $slug): RedirectResponse
    {
        try {
            $this->plugins->disable($slug, auth()->id());
            return back()->with('status', "Plugin '{$slug}' disabled.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function uninstall(Request $request, string $slug): RedirectResponse
    {
        $request->validate(['confirm_uninstall' => ['required', 'accepted']]);
        try {
            $this->plugins->uninstall($slug, false, auth()->id());
            return back()->with('status', "Plugin '{$slug}' uninstalled cleanly. Shared LMS records remain intact.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }


    public function resetSettings(string $slug): RedirectResponse
    {
        try {
            abort_unless(PluginRecord::query()->where('slug', $slug)->exists(), 404);
            $this->plugins->resetSettingsToDefaults($slug, auth()->id());

            return back()->with('status', "Settings for '{$slug}' restored to manifest defaults. A private recovery copy was retained.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function deleteToRecycleBin(string $slug): RedirectResponse
    {
        $source = null;
        $destination = null;
        $wasInstalled = false;
        $wasEnabled = false;
        $disabledByOperation = false;
        $uninstalledByOperation = false;

        try {
            abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $slug) === 1, 404);
            $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
            if ($record->is_core || $slug === config('plugins.core_identifier')) {
                throw new RuntimeException('Protected Core cannot be deleted.');
            }
            $manifest = $this->plugins->discoverManifests()[$slug] ?? null;
            if (!$manifest) {
                throw new RuntimeException('Plugin files are missing; use the recovery package to restore them.');
            }

            $modulesRoot = realpath((string) config('plugins.modules_path'));
            $source = realpath($manifest->basePath);
            if (!$modulesRoot || !$source || dirname($source) !== $modulesRoot || is_link($manifest->basePath)) {
                throw new RuntimeException('Plugin package path is outside the registered modules directory.');
            }
            $checksums = $this->directoryChecksums($source);
            if ($checksums === []) {
                throw new RuntimeException('Empty plugin packages cannot be archived.');
            }

            $wasInstalled = (bool) $record->is_installed;
            $wasEnabled = (bool) $record->is_enabled;
            if ($wasEnabled) {
                $this->plugins->disable($slug, auth()->id());
                $disabledByOperation = true;
            }
            if ($wasInstalled) {
                $this->plugins->uninstall($slug, false, auth()->id());
                $uninstalledByOperation = true;
            }

            $archiveId = (string) \Illuminate\Support\Str::uuid();
            $trashRoot = storage_path('app/plugin-recycle-bin');
            File::ensureDirectoryExists($trashRoot);
            $destination = $trashRoot . DIRECTORY_SEPARATOR . $archiveId;
            if (!File::moveDirectory($source, $destination)) {
                throw new RuntimeException('Plugin files could not be moved into the recycle bin.');
            }
            if ($checksums !== $this->directoryChecksums($destination)) {
                File::moveDirectory($destination, $source);
                throw new RuntimeException('Plugin archive integrity verification failed; the original package was restored.');
            }

            PluginArchive::query()->create([
                'plugin_slug' => $slug,
                'plugin_name' => $record->name,
                'version' => $record->version,
                'archive_path' => $destination,
                'manifest_snapshot' => $manifest->toArray(),
                'files_sha256' => $checksums,
                'was_installed' => $wasInstalled,
                'was_enabled' => $wasEnabled,
                'deleted_by' => auth()->id(),
                'deleted_at' => now(),
            ]);

            return back()->with('status', "Plugin '{$slug}' moved to the recycle bin. Its previous installation state, settings and LMS business data were preserved.");
        } catch (\Throwable $e) {
            if ($destination && is_dir($destination) && $source && !file_exists($source)) {
                File::moveDirectory($destination, $source);
            }
            if ($source && is_dir($source) && $wasInstalled && ($disabledByOperation || $uninstalledByOperation)) {
                try {
                    if ($uninstalledByOperation) {
                        $this->plugins->install($slug, auth()->id());
                        if (!$wasEnabled) {
                            $this->plugins->disable($slug, auth()->id());
                        }
                    } elseif ($disabledByOperation && $wasEnabled) {
                        $this->plugins->enable($slug, auth()->id());
                    }
                } catch (\Throwable $rollbackError) {
                    report($rollbackError);
                }
            }
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function restoreFromRecycleBin(string $archive): RedirectResponse
    {
        $destination = null;
        $archivePath = null;

        try {
            $item = PluginArchive::query()->findOrFail($archive);
            abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $item->plugin_slug) === 1, 404);

            $trashRoot = realpath(storage_path('app/plugin-recycle-bin'));
            $archivePath = realpath($item->archive_path);
            if (!$trashRoot || !$archivePath || dirname($archivePath) !== $trashRoot
                || is_link($item->archive_path) || !is_dir($archivePath)) {
                throw new RuntimeException('Archived plugin path is missing or outside the protected recycle-bin directory.');
            }

            $actualChecksums = $this->directoryChecksums($archivePath);
            $expectedChecksums = $item->files_sha256;
            if (is_array($expectedChecksums) && $expectedChecksums !== [] && $actualChecksums !== $expectedChecksums) {
                throw new RuntimeException('Archived plugin integrity verification failed; restore was cancelled.');
            }

            $manifest = \App\Core\Plugins\PluginManifest::fromDirectory($archivePath);
            if ($manifest->slug !== $item->plugin_slug || $manifest->isCore || !$this->plugins->isCompatible($manifest)) {
                throw new RuntimeException('Archived plugin manifest identity or Core compatibility is invalid.');
            }

            $destination = rtrim((string) config('plugins.modules_path'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item->plugin_slug;
            if (file_exists($destination)) {
                throw new RuntimeException('A plugin with this slug already exists. Resolve the conflict before restoring.');
            }
            File::ensureDirectoryExists(dirname($destination));
            if (!File::moveDirectory($archivePath, $destination)) {
                throw new RuntimeException('Archived plugin files could not be restored.');
            }

            try {
                if ((bool) $item->was_installed) {
                    $this->plugins->install($item->plugin_slug, auth()->id());
                    if (!(bool) $item->was_enabled) {
                        $this->plugins->disable($item->plugin_slug, auth()->id());
                    }
                } else {
                    $this->plugins->syncCatalog();
                }
                $item->delete();
            } catch (\Throwable $e) {
                if (is_dir($destination)) {
                    File::moveDirectory($destination, $archivePath);
                }
                throw $e;
            }

            $state = $item->was_installed ? ((bool) $item->was_enabled ? 'enabled' : 'disabled') : 'uninstalled';
            return back()->with('status', "Plugin '{$item->plugin_slug}' restored with its previous {$state} state.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function permanentlyDeleteFromRecycleBin(string $archive): RedirectResponse
    {
        try {
            $item = PluginArchive::query()->findOrFail($archive);
            $trashRoot = realpath(storage_path('app/plugin-recycle-bin'));
            $archivePath = realpath($item->archive_path);
            if (!$trashRoot || !$archivePath || !is_dir($archivePath) || is_link($item->archive_path)
                || !str_starts_with($archivePath, rtrim($trashRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Archived plugin path is missing or outside the protected recycle-bin directory.');
            }

            // Never create a "verified" recovery copy from an archive that has
            // already been modified since it entered the recycle bin.
            $sourceChecksums = $this->directoryChecksums($archivePath);
            $expectedChecksums = $item->files_sha256;
            if (!is_array($expectedChecksums) || $expectedChecksums === [] || $sourceChecksums !== $expectedChecksums) {
                throw new RuntimeException('Permanent deletion cancelled: archived plugin integrity verification failed.');
            }

            $backupId = 'archive-' . (int) $item->id . '-' . substr(hash('sha256', $item->plugin_slug), 0, 12);
            $backupRoot = storage_path('app/plugin-permanent-delete-backups');
            $backupPath = $backupRoot . DIRECTORY_SEPARATOR . $backupId;
            if (is_dir($backupPath)) {
                throw new RuntimeException('A private recovery snapshot already exists; resolve it before retrying.');
            }

            File::ensureDirectoryExists($backupRoot);
            @chmod($backupRoot, 0700);
            if (!File::copyDirectory($archivePath, $backupPath)) {
                File::deleteDirectory($backupPath);
                throw new RuntimeException('Permanent deletion cancelled: private recovery snapshot could not be created.');
            }
            @chmod($backupPath, 0700);

            if ($sourceChecksums !== $this->directoryChecksums($backupPath)) {
                File::deleteDirectory($backupPath);
                throw new RuntimeException('Permanent deletion cancelled: private recovery snapshot failed integrity verification.');
            }

            if (!File::deleteDirectory($archivePath)) {
                throw new RuntimeException('Private recovery snapshot was created, but the recycle-bin package could not be removed.');
            }

            PluginSetting::query()->where('plugin_slug', $item->plugin_slug)->delete();
            PluginRecord::query()->where('slug', $item->plugin_slug)->where('is_core', false)->delete();
            $slug = $item->plugin_slug;
            $item->delete();
            app(\App\Core\Security\AuditLogger::class)->log(
                'plugin.archive_permanently_deleted',
                'plugin',
                $slug,
                ['private_backup_id' => $backupId, 'package_files_removed_from_recycle_bin' => true],
                auth()->id(),
            );

            return back()->with('status', "Plugin '{$slug}' permanently deleted from Recycle Bin. A verified private recovery snapshot was retained.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    private function directoryChecksums(string $directory): array
    {
        $root = realpath($directory);
        if (!$root || !is_dir($root) || is_link($directory)) {
            throw new RuntimeException('Plugin archive directory is missing or unsafe.');
        }

        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed in plugin archives.');
            }
            if ($entry->isDir()) {
                continue;
            }
            $path = $entry->getPathname();
            $resolved = realpath($path);
            if (!$resolved || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Plugin archive contains a path outside its protected directory.');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
            $checksum = hash_file('sha256', $resolved);
            if (!is_string($checksum) || $checksum === '') {
                throw new RuntimeException('Could not verify a plugin archive file checksum.');
            }
            $checksums[$relative] = $checksum;
        }
        ksort($checksums);

        return $checksums;
    }

    public function configure(string $slug): View
    {
        $manifest = $this->plugins->discoverManifests()[$slug] ?? abort(404);
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
        $values = [];
        $configuredSecrets = [];
        foreach ($manifest->settingsSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            $isSecret = (bool) ($field['secret'] ?? false);
            $stored = $this->plugins->getSetting($slug, $key);
            $values[$key] = $isSecret ? '' : ($stored ?? '');
            if ($isSecret) $configuredSecrets[$key] = $stored !== null && $stored !== '';
        }

        return view('admin.plugins.configure', compact('manifest', 'record', 'values', 'configuredSecrets'));
    }


    public function testConnection(string $slug): RedirectResponse
    {
        try {
            $result = match ($slug) {
                'gateway-stripe' => app(StripeGatewayService::class)->testCredentials(),
                'gateway-paypal' => app(PaypalGatewayService::class)->testCredentials(),
                'gateway-sslcommerz' => app(SslcommerzGatewayService::class)->testCredentials(),
                'gateway-card' => throw new \RuntimeException('Visa/Mastercard is a card network, not a processor. Connect an acquiring bank/PSP adapter or use the Stripe plugin for Visa/Mastercard processing.'),
                default => throw new \RuntimeException('This plugin does not provide an automated payment credential test.'),
            };

            $mode = isset($result['mode']) ? ' (' . $result['mode'] . ')' : '';
            return back()->with('status', strtoupper(str_replace('gateway-', '', $slug)) . ' connection verified' . $mode . '.');
        } catch (\Throwable $e) {
            return back()->withErrors(['gateway_connection' => $e->getMessage()]);
        }
    }

    public function saveConfiguration(Request $request, string $slug): RedirectResponse
    {
        $manifest = $this->plugins->discoverManifests()[$slug] ?? abort(404);
        $values = [];
        $secretKeys = [];

        foreach ($manifest->settingsSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $isSecret = (bool) ($field['secret'] ?? false);
            if ($isSecret) {
                $secretKeys[] = $key;
                $submitted = (string) $request->input("settings.{$key}", '');
                if ($submitted !== '') {
                    $values[$key] = $submitted;
                }
            } else {
                $values[$key] = (string) $request->input("settings.{$key}", '');
            }
        }

        $this->plugins->saveSettings($slug, $values, $secretKeys, auth()->id());

        return redirect()->route('admin.plugins.index')->with('status', "Encrypted configuration saved for '{$manifest->name}'.");
    }
}
