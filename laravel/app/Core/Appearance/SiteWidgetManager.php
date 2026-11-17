<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

final class SiteWidgetManager
{
    private const TYPES = ['text', 'notice', 'search', 'cta'];
    private const AREAS = ['course-sidebar', 'site-sidebar'];

    public function all(): array
    {
        if (!Schema::hasTable('system_settings')) return [];
        $raw = SystemSetting::query()->where('key', 'site.widgets')->value('value');
        $items = is_string($raw) ? json_decode($raw, true) : [];
        if (!is_array($items)) return [];

        return array_values(array_filter(array_map(function ($item): ?array {
            if (!is_array($item) || !in_array($item['type'] ?? '', self::TYPES, true) || !in_array($item['area'] ?? '', self::AREAS, true)) return null;
            $url = $this->safeUrl((string) ($item['url'] ?? ''));
            return [
                'type' => $item['type'],
                'area' => $item['area'],
                'title' => mb_substr(trim((string) ($item['title'] ?? '')), 0, 80),
                'content' => mb_substr(trim((string) ($item['content'] ?? '')), 0, 600),
                'label' => mb_substr(trim((string) ($item['label'] ?? '')), 0, 50),
                'url' => $url,
                'visible' => (bool) ($item['visible'] ?? false),
                'order' => max(1, min(100, (int) ($item['order'] ?? 10))),
            ];
        }, $items), static fn ($item) => $item !== null && $item['visible'] && $item['title'] !== ''));
    }

    public function save(array $items, ?int $userId = null): void
    {
        $safe = [];
        foreach (array_slice($items, 0, 16) as $item) {
            if (!is_array($item) || !in_array($item['type'] ?? '', self::TYPES, true) || !in_array($item['area'] ?? '', self::AREAS, true)) continue;
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') continue;
            $url = $this->safeUrl((string) ($item['url'] ?? ''));
            $type = $item['type'];
            if ($type === 'cta' && ($url === null || trim((string) ($item['label'] ?? '')) === '')) continue;
            $safe[] = [
                'type' => $type,
                'area' => $item['area'],
                'title' => mb_substr($title, 0, 80),
                'content' => mb_substr(trim((string) ($item['content'] ?? '')), 0, 600),
                'label' => mb_substr(trim((string) ($item['label'] ?? '')), 0, 50),
                'url' => $url,
                'visible' => filter_var($item['visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'order' => max(1, min(100, (int) ($item['order'] ?? 10))),
            ];
        }
        SystemSetting::query()->updateOrCreate(['key' => 'site.widgets'], [
            'value' => json_encode($safe, JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'updated_by' => $userId,
        ]);
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return mb_substr($url, 0, 500);
        if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return mb_substr($url, 0, 500);
        return null;
    }
}
