<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

final class SiteIdentityManager
{
    private const DEFAULTS = [
        'tagline' => 'Learn English with confidence',
        'footer_text' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'address' => '',
        'login_title' => 'Welcome Back!',
        'login_message' => 'Please sign in to continue',
        'logo_path' => '',
        'favicon_path' => '',
    ];

    public function values(): array
    {
        $values = self::DEFAULTS;
        $stored = collect();
        if (Schema::hasTable('system_settings')) {
            $stored = SystemSetting::query()
                ->whereIn('key', array_map(static fn (string $key): string => 'branding.' . $key, array_keys(self::DEFAULTS)))
                ->pluck('value', 'key');

            foreach (self::DEFAULTS as $key => $default) {
                $value = $stored->get('branding.' . $key);
                if (is_string($value)) {
                    $values[$key] = $value;
                }
            }
        }

        $values['logo_url'] = $this->safeUrl($stored?->get('branding.logo_url')) ?? $this->publicUrl($values['logo_path']);
        $values['favicon_url'] = $this->safeUrl($stored?->get('branding.favicon_url')) ?? $this->publicUrl($values['favicon_path']);

        return $values;
    }

    private function safeUrl(mixed $url): ?string
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }

    private function publicUrl(string $path)
    {
        if ($path === '' || !preg_match('~^/uploads/site-branding/[A-Za-z0-9._-]+$~', $path)) {
            return null;
        }

        return asset(ltrim($path, '/'));
    }
}
