<?php

declare(strict_types=1);

namespace Plugins\\Modules\\PageBuilder\\Services;

use App\\Models\\SystemSetting;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\Support\\Str;

final class ReusableBlockLibrary
{
    private const KEY = 'page_builder.reusable_blocks';
    private const TYPES = ['heading', 'text', 'button'];

    public function all(): array
    {
        if (!Schema::hasTable('system_settings')) return [];
        $raw = SystemSetting::query()->where('key', self::KEY)->value('value');
        $items = is_string($raw) ? json_decode($raw, true) : [];
        if (!is_array($items)) return [];

        return array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && is_string($item['id'] ?? null)
                && in_array($item['type'] ?? null, self::TYPES, true)
                && is_string($item['title'] ?? null)
                && is_string($item['content'] ?? null);
        }));
    }

    public function save(string $type, string $title, string $content, ?string $url = null, ?int $userId = null): array
    {
        $items = $this->all();
        $block = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'title' => mb_substr(trim($title), 0, 80),
            'content' => mb_substr(trim($content), 0, 12000),
            'url' => $this->safeUrl((string) $url),
            'created_at' => now()->toIso8601String(),
        ];
        $items[] = $block;
        $items = array_slice($items, -100);
        SystemSetting::query()->updateOrCreate(['key' => self::KEY], [
            'value' => json_encode($items, JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'updated_by' => $userId,
        ]);
        return $block;
    }

    public function delete(string $id, ?int $userId = null): bool
    {
        $items = $this->all();
        $filtered = array_values(array_filter($items, static fn ($item) => !hash_equals((string) $item['id'], $id)));
        if (count($filtered) === count($items)) return false;
        SystemSetting::query()->updateOrCreate(['key' => self::KEY], [
            'value' => json_encode($filtered, JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'updated_by' => $userId,
        ]);
        return true;
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return mb_substr($url, 0, 2048);
        if (filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return mb_substr($url, 0, 2048);
        return null;
    }
}
