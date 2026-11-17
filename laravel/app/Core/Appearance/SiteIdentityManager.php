<?php

declare(strict_types=1);

namespace App\\Core\\Appearance;

use App\\Models\\SystemSetting;
use Illuminate\\Support\\Facades\\Schema;

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
                ->whereIn('key', array_merge(array_map(static fn (string $key): string => 'branding.' . $key, array_keys(self::DEFAULTS)), ['branding.logo_url', 'branding.favicon_url']))
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
        if (!is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/storage/media-library/') && !str_contains($url, '..')) {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $validHost = $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        return $validHost ? $url : null;
    }

    private function publicUrl(string $path)
    {
        if ($path === '' || !preg_match('~^/uploads/site-branding/[A-Za-z0-9._-]+$~', $path)) {
            return null;
        }

        return asset(ltrim($path, '/'));
    }
}
