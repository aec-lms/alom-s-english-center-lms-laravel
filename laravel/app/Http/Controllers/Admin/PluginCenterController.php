<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\PluginRecord;
use Plugins\Modules\GatewayStripe\Services\StripeGatewayService;
use Plugins\Modules\GatewayPaypal\Services\PaypalGatewayService;
use Plugins\Modules\GatewaySslcommerz\Services\SslcommerzGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        return view('admin.plugins.index', compact('records'));
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
        $rules = ['settings' => ['sometimes', 'array']];
        $allowedKeys = [];

        foreach ($manifest->settingsSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $key) !== 1) {
                throw new \RuntimeException("Plugin '{$slug}' contains an invalid setting key.");
            }

            $allowedKeys[] = $key;
            $type = (string) ($field['type'] ?? 'text');
            $fieldRules = ['nullable', 'string', 'max:2048'];
            if (!empty($field['required'])) {
                $fieldRules = ['required', 'string', 'max:2048'];
            }
            if ($type === 'select') {
                $options = (array) ($field['options'] ?? []);
                if ($options === []) {
                    throw new \RuntimeException("Plugin '{$slug}' has no options for select setting '{$key}'.");
                }
                $fieldRules[] = Rule::in(array_map('strval', array_keys($options)));
            }
            $rules["settings.{$key}"] = $fieldRules;

            if ((bool) ($field['secret'] ?? false)) {
                $secretKeys[] = $key;
            }
        }

        $validated = Validator::make($request->all(), $rules)->validate();
        $submittedSettings = $validated['settings'] ?? [];
        $unknownKeys = array_diff(array_keys($request->input('settings', [])), $allowedKeys);
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'settings' => 'Unsupported plugin setting(s): ' . implode(', ', $unknownKeys),
            ]);
        }

        foreach ($manifest->settingsSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $isSecret = (bool) ($field['secret'] ?? false);
            $submitted = $submittedSettings[$key] ?? '';
            if ($isSecret) {
                if ($submitted !== '') {
                    $values[$key] = $submitted;
                }
            } else {
                $values[$key] = (string) $submitted;
            }
        }

        $this->plugins->saveSettings($slug, $values, $secretKeys, auth()->id());

        return redirect()->route('admin.plugins.index')->with('status', "Encrypted configuration saved for '{$manifest->name}'.");
    }
}
