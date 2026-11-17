<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
            $record->setAttribute('is_compatible', $manifest ? $this->plugins->isCompatible($manifest) : false);
            $record->setAttribute('has_settings', $manifest && count($manifest->settingsSchema) > 0);
            $record->setAttribute('manifest_version', $manifest?->version ?? $record->version);
            $record->setAttribute('update_available', $manifest && version_compare($manifest->version, $record->version, '>'));
            $record->setAttribute('recent_logs', $recentLogs->get($record->slug, collect())->take(3));
            return $record;
        });

        $archives = Schema::hasTable('plugin_archives') ? PluginArchive::query()->latest('deleted_at')->get() : collect();

        return view('admin.plugins.index', compact('records', 'archives'));
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'plugin_zip' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ]);

        $stored = $request->file('plugin_zip')->store('plugin-uploads');
        $absolutePath = storage_path('app/' . $stored);
        try {
            $record = $this->plugins->installOrUpdateFromZip($absolutePath, auth()->id());
            return back()->with('status', "Plugin '{$record->name}' (v{$record->version}) installed/updated successfully.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        } finally {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
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
            $manifest = $this->plugins->discoverManifests()[$slug] ?? abort(404);
            PluginSetting::query()->where('plugin_slug', $slug)->delete();
            $defaults = [];
            $secretKeys = [];
            foreach ($manifest->settingsSchema as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '' || !array_key_exists('default', $field)) continue;
                $defaults[$key] = $field['default'];
                if (!empty($field['secret'])) $secretKeys[] = $key;
            }
            if ($defaults !== []) $this->plugins->saveSettings($slug, $defaults, $secretKeys, auth()->id());
            app(\App\Core\Security\AuditLogger::class)->log('plugin.settings_reset', 'plugin', $slug, ['default_keys' => array_keys($defaults)], auth()->id());
            return back()->with('status', "Settings for '{$slug}' restored to manifest defaults.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function deleteToRecycleBin(string $slug): RedirectResponse
    {
        try {
            $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
            if ($record->is_core || $slug === config('plugins.core_identifier')) {
                throw new RuntimeException('Protected Core cannot be deleted.');
            }
            $manifest = $this->plugins->discoverManifests()[$slug] ?? null;
            if (!$manifest) {
                throw new RuntimeException('Plugin files are missing; use the recovery package to restore them.');
            }
            if ($record->is_enabled) $this->plugins->disable($slug, auth()->id());
            if ($record->is_installed) $this->plugins->uninstall($slug, false, auth()->id());

            $archiveId = (string) \Illuminate\Support\Str::uuid();
            $source = $manifest->basePath;
            $destination = storage_path('app/plugin-recycle-bin/' . $archiveId);
            File::ensureDirectoryExists(dirname($destination));
            if (!File::moveDirectory($source, $destination)) {
                throw new RuntimeException('Plugin files could not be moved into the recycle bin.');
            }
            try {
                PluginArchive::query()->create([
                    'plugin_slug' => $slug, 'plugin_name' => $record->name,
                    'version' => $record->version, 'archive_path' => $destination,
                    'manifest_snapshot' => $manifest->toArray(), 'deleted_by' => auth()->id(), 'deleted_at' => now(),
                ]);
            } catch (\Throwable $e) {
                File::moveDirectory($destination, $source);
                throw $e;
            }
            return back()->with('status', "Plugin '{$slug}' moved to the recycle bin. Its settings and LMS business data were preserved.");
        } catch (\Throwable $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
    }

    public function restoreFromRecycleBin(string $archive): RedirectResponse
    {
        try {
            $item = PluginArchive::query()->findOrFail($archive);
            $destination = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $item->plugin_slug;
            if (is_dir($destination)) throw new RuntimeException('A plugin with this slug already exists. Resolve the conflict before restoring.');
            if (!is_dir($item->archive_path) || !File::moveDirectory($item->archive_path, $destination)) {
                throw new RuntimeException('Archived plugin files are missing or could not be restored.');
            }
            try {
                $this->plugins->install($item->plugin_slug, auth()->id());
                $item->delete();
            } catch (\Throwable $e) {
                File::moveDirectory($destination, $item->archive_path);
                throw $e;
            }
            return back()->with('status', "Plugin '{$item->plugin_slug}' restored and enabled.");
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
            if (!$trashRoot || !$archivePath || !is_dir($archivePath)
                || !str_starts_with($archivePath, $trashRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Archived plugin path is missing or outside the protected recycle-bin directory.');
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

            $sourceChecksums = $this->directoryChecksums($archivePath);
            if ($sourceChecksums === [] || $sourceChecksums !== $this->directoryChecksums($backupPath)) {
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
        $checksums = [];
        foreach (File::allFiles($directory) as $file) {
            $relative = ltrim(str_replace($directory, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $checksum = hash_file('sha256', $file->getPathname());
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
