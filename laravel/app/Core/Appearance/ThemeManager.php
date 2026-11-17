<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

class ThemeManager
{
    public const DEFAULTS = [
        'primary' => '#0057FF',
        'primary_hover' => '#0046D5',
        'secondary' => '#022D41',
        'accent' => '#38BDF8',
        'page_bg' => '#F1F5F9',
        'surface' => '#FFFFFF',
        'surface_alt' => '#F8FAFC',
        'text' => '#0F172A',
        'muted' => '#64748B',
        'border' => '#E2E8F0',
        'sidebar_bg' => '#022233',
        'sidebar_text' => '#E2E8F0',
        'header_bg' => '#FFFFFF',
        'success' => '#059669',
        'warning' => '#D97706',
        'danger' => '#E11D48',
        'info' => '#0284C7',
        'link' => '#0057FF',
        'button_text' => '#FFFFFF',
        'input_bg' => '#FFFFFF',
        'input_text' => '#0F172A',
    ];

    public function scopeForCurrentRequest(): string
    {
        $route = request()->route();
        $action = (string) ($route?->getActionName() ?? '');
        if (preg_match('/^Plugins\\\\Modules\\\\([A-Za-z0-9]+)\\\\/', $action, $matches)) {
            $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $matches[1]));
            return 'plugin.' . $slug;
        }

        $name = (string) ($route?->getName() ?? '');
        if (preg_match('/^plugins\\.([a-z0-9_-]+)\\./', $name, $matches)) {
            return 'plugin.' . $matches[1];
        }

        return 'global';
    }

    public function colors(?string $scope = null): array
    {
        $scope ??= $this->scopeForCurrentRequest();
        $global = $this->read('global');
        $scoped = $scope === 'global' ? [] : $this->read($scope);

        return array_merge(self::DEFAULTS, $global, $scoped);
    }

    public function cssVariables(?string $scope = null): string
    {
        $colors = $this->colors($scope);
        $variables = [];
        foreach ($colors as $key => $value) {
            if (isset(self::DEFAULTS[$key]) && is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $variables[] = '--aec-' . str_replace('_', '-', $key) . ':' . $value;
            }
        }

        return implode(';', $variables);
    }

    public function save(string $scope, array $colors, ?int $userId = null): void
    {
        $safe = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $colors[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $safe[$key] = strtoupper($value);
            }
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'appearance.theme.' . $scope],
            ['value' => json_encode($safe, JSON_THROW_ON_ERROR), 'is_encrypted' => false, 'updated_by' => $userId]
        );
    }

    public function reset(string $scope): void
    {
        SystemSetting::query()->where('key', 'appearance.theme.' . $scope)->delete();
    }

    private function read(string $scope): array
    {
        if (!Schema::hasTable('system_settings')) {
            return [];
        }

        $value = SystemSetting::query()->where('key', 'appearance.theme.' . $scope)->value('value');
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_intersect_key($decoded, self::DEFAULTS) : [];
    }
}
