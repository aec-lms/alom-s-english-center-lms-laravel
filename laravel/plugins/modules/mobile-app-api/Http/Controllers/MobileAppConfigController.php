<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\MobileAppApi\Models\MobileDeviceToken;

class MobileAppConfigController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $config = $this->loadConfig();
        $devices = Schema::hasTable('mobile_device_tokens')
            ? MobileDeviceToken::query()->with('user:id,name,email')->latest('last_seen_at')->limit(50)->get()
            : collect();
        $iosCount = Schema::hasTable('mobile_device_tokens') ? MobileDeviceToken::query()->where('platform', 'ios')->count() : 0;
        $androidCount = Schema::hasTable('mobile_device_tokens') ? MobileDeviceToken::query()->where('platform', 'android')->count() : 0;

        return view('mobile-app-api::index', compact('config', 'devices', 'iosCount', 'androidCount'));
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ios_bundle_id' => ['required', 'string', 'max:140'],
            'ios_store_url' => ['nullable', 'string', 'max:255'],
            'ios_min_version' => ['required', 'string', 'max:30'],
            'ios_latest_version' => ['required', 'string', 'max:30'],
            'android_package_name' => ['required', 'string', 'max:140'],
            'android_store_url' => ['nullable', 'string', 'max:255'],
            'android_min_version' => ['required', 'string', 'max:30'],
            'android_latest_version' => ['required', 'string', 'max:30'],
            'deep_link_scheme' => ['required', 'string', 'max:50'],
            'maintenance_message' => ['nullable', 'string', 'max:320'],
        ]);

        $payload = [
            'ios_enabled' => $request->boolean('ios_enabled'),
            'ios_bundle_id' => $validated['ios_bundle_id'],
            'ios_store_url' => $validated['ios_store_url'] ?: 'https://apps.apple.com/app/aloms-english-center',
            'ios_min_version' => $validated['ios_min_version'],
            'ios_latest_version' => $validated['ios_latest_version'],
            'ios_force_update' => $request->boolean('ios_force_update'),
            'android_enabled' => $request->boolean('android_enabled'),
            'android_package_name' => $validated['android_package_name'],
            'android_store_url' => $validated['android_store_url'] ?: 'https://play.google.com/store/apps/details?id=com.alomsenglishcenter.app',
            'android_min_version' => $validated['android_min_version'],
            'android_latest_version' => $validated['android_latest_version'],
            'android_force_update' => $request->boolean('android_force_update'),
            'deep_link_scheme' => $validated['deep_link_scheme'],
            'push_enabled' => $request->boolean('push_enabled'),
            'maintenance_mode' => $request->boolean('maintenance_mode'),
            'maintenance_message' => $validated['maintenance_message'] ?? "We are upgrading our servers. Please check back shortly.",
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'mobile_app_config'],
            [
                'value' => json_encode($payload, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_by' => $request->user()?->id,
            ]
        );

        // Keep homepage app banner URLs synchronized automatically
        $homeRaw = SystemSetting::query()->where('key', 'homepage_customizer')->value('value');
        $home = is_string($homeRaw) ? (json_decode($homeRaw, true) ?: []) : [];
        $home['app_ios_url'] = $payload['ios_store_url'];
        $home['app_android_url'] = $payload['android_store_url'];
        SystemSetting::query()->updateOrCreate(
            ['key' => 'homepage_customizer'],
            [
                'value' => json_encode($home, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_by' => $request->user()?->id,
            ]
        );

        $this->audit->log('mobile.config_saved', 'system_setting', 'mobile_app_config', []);

        return back()->with('status', 'Apple iOS & Google Android mobile app settings updated.');
    }

    public static function defaultConfig(): array
    {
        return [
            'ios_enabled' => true,
            'ios_bundle_id' => 'com.alomsenglishcenter.ios',
            'ios_store_url' => 'https://apps.apple.com/app/aloms-english-center',
            'ios_min_version' => '1.0.0',
            'ios_latest_version' => '2.0.0',
            'ios_force_update' => false,
            'android_enabled' => true,
            'android_package_name' => 'com.alomsenglishcenter.app',
            'android_store_url' => 'https://play.google.com/store/apps/details?id=com.alomsenglishcenter.app',
            'android_min_version' => '1.0.0',
            'android_latest_version' => '2.0.0',
            'android_force_update' => false,
            'deep_link_scheme' => 'alomsenglishcenter://',
            'push_enabled' => true,
            'maintenance_mode' => false,
            'maintenance_message' => "We are upgrading our servers. Please check back shortly.",
        ];
    }

    private function loadConfig(): array
    {
        $defaults = self::defaultConfig();
        if (!Schema::hasTable('system_settings')) {
            return $defaults;
        }
        $raw = SystemSetting::query()->where('key', 'mobile_app_config')->value('value');
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
    }
}
