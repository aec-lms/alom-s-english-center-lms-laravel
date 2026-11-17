<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

class ThemeManager
{
    public const DEFAULTS = [
        'primary' => '#0057FF', 'primary_hover' => '#0046D5', 'secondary' => '#022D41',
        'accent' => '#38BDF8', 'page_bg' => '#F1F5F9', 'surface' => '#FFFFFF',
        'surface_alt' => '#F8FAFC', 'text' => '#0F172A', 'muted' => '#64748B',
        'border' => '#E2E8F0', 'sidebar_bg' => '#022233', 'sidebar_text' => '#E2E8F0',
        'header_bg' => '#FFFFFF', 'success' => '#059669', 'warning' => '#D97706',
        'danger' => '#E11D48', 'info' => '#0284C7', 'link' => '#0057FF',
        'button_text' => '#FFFFFF', 'input_bg' => '#FFFFFF', 'input_text' => '#0F172A',
    ];

    public const DESIGN_DEFAULTS = [
        'font_family' => 'plus-jakarta',
        'font_size' => '16',
        'heading_weight' => '800',
        'content_width' => '1120',
        'border_radius' => '16',
        'density' => 'comfortable',
    ];

    private const FONT_STACKS = [
        'plus-jakarta' => "'Plus Jakarta Sans', 'Noto Sans Bengali', sans-serif",
        'inter' => "Inter, 'Noto Sans Bengali', sans-serif",
        'noto-sans' => "'Noto Sans Bengali', 'Noto Sans', sans-serif",
        'system' => "system-ui, 'Noto Sans Bengali', sans-serif",
    ];

    public function scopeForCurrentRequest(): string
    {
        $route = request()->route();
        $action = (string) ($route?->getActionName() ?? '');
        if (preg_match('/^Plugins\\Modules\\([A-Za-z0-9]+)\\/', $action, $matches)) {
            $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $matches[1]));
            return 'plugin.' . $slug;
        }

        $name = (string) ($route?->getName() ?? '');
        if (preg_match('/^plugins\.([a-z0-9_-]+)\./', $name, $matches)) {
            return 'plugin.' . $matches[1];
        }

        return 'global';
    }

    public function colors(?string $scope = null): array
    {
        $scope ??= $this->scopeForCurrentRequest();
        $global = $this->read('appearance.theme.global', self::DEFAULTS);
        $scoped = $scope === 'global' ? [] : $this->read('appearance.theme.' . $scope, self::DEFAULTS);
        return array_merge(self::DEFAULTS, $global, $scoped);
    }

    public function cssVariables(?string $scope = null): string
    {
        $variables = [];
        foreach ($this->colors($scope) as $key => $value) {
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

    public function design(?string $scope = null): array
    {
        $scope ??= $this->scopeForCurrentRequest();
        $global = $this->read('appearance.design.global', self::DESIGN_DEFAULTS);
        $scoped = $scope === 'global' ? [] : $this->read('appearance.design.' . $scope, self::DESIGN_DEFAULTS);
        return $this->sanitizeDesign(array_merge(self::DESIGN_DEFAULTS, $global, $scoped));
    }

    public function saveDesign(string $scope, array $design, ?int $userId = null): void
    {
        $safe = $this->sanitizeDesign(array_merge(self::DESIGN_DEFAULTS, $design));
        SystemSetting::query()->updateOrCreate(
            ['key' => 'appearance.design.' . $scope],
            ['value' => json_encode($safe, JSON_THROW_ON_ERROR), 'is_encrypted' => false, 'updated_by' => $userId]
        );
    }

    public function cssDesignVariables(?string $scope = null): string
    {
        $design = $this->design($scope);
        $font = self::FONT_STACKS[$design['font_family']];
        return implode(';', [
            '--aec-font-family:' . $font,
            '--aec-font-size:' . $design['font_size'] . 'px',
            '--aec-heading-weight:' . $design['heading_weight'],
            '--aec-content-width:' . $design['content_width'] . 'px',
            '--aec-radius:' . $design['border_radius'] . 'px',
            '--aec-density:' . ($design['density'] === 'compact' ? '0.8' : '1'),
        ]);
    }

    public function reset(string $scope): void
    {
        SystemSetting::query()->whereIn('key', [
            'appearance.theme.' . $scope,
            'appearance.design.' . $scope,
        ])->delete();
    }

    private function sanitizeDesign(array $design): array
    {
        $safe = [];
        $safe['font_family'] = array_key_exists($design['font_family'] ?? '', self::FONT_STACKS)
            ? $design['font_family'] : self::DESIGN_DEFAULTS['font_family'];
        $safe['font_size'] = in_array((string) ($design['font_size'] ?? ''), ['14', '15', '16', '17', '18'], true)
            ? (string) $design['font_size'] : self::DESIGN_DEFAULTS['font_size'];
        $safe['heading_weight'] = in_array((string) ($design['heading_weight'] ?? ''), ['600', '700', '800', '900'], true)
            ? (string) $design['heading_weight'] : self::DESIGN_DEFAULTS['heading_weight'];
        $safe['content_width'] = in_array((string) ($design['content_width'] ?? ''), ['960', '1120', '1280', '1440', '1600'], true)
            ? (string) $design['content_width'] : self::DESIGN_DEFAULTS['content_width'];
        $safe['border_radius'] = in_array((string) ($design['border_radius'] ?? ''), ['8', '12', '16', '20', '24'], true)
            ? (string) $design['border_radius'] : self::DESIGN_DEFAULTS['border_radius'];
        $safe['density'] = in_array(($design['density'] ?? ''), ['compact', 'comfortable'], true)
            ? $design['density'] : self::DESIGN_DEFAULTS['density'];
        return $safe;
    }

    private function read(string $key, array $defaults): array
    {
        if (!Schema::hasTable('system_settings')) return [];
        $value = SystemSetting::query()->where('key', $key)->value('value');
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_intersect_key($decoded, $defaults) : [];
    }
}
