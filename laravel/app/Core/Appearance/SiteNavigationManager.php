<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

final class SiteNavigationManager
{
    public const DEFAULTS = [
        'primary' => [
            ['label' => 'Home', 'url' => '/', 'order' => 10, 'hidden' => false],
            ['label' => 'Courses', 'url' => '/courses', 'order' => 20, 'hidden' => false],
            ['label' => 'Learn', 'url' => '/learn', 'order' => 30, 'hidden' => false],
            ['label' => 'Instructors', 'url' => '/instructors', 'order' => 40, 'hidden' => false],
            ['label' => 'Exams', 'url' => '/exams', 'order' => 50, 'hidden' => false],
            ['label' => 'Products', 'url' => '/products', 'order' => 60, 'hidden' => false],
            ['label' => 'E-Books', 'url' => '/ebooks', 'order' => 70, 'hidden' => false],
            ['label' => 'Result', 'url' => '/result', 'order' => 80, 'hidden' => false],
        ],
        'footer' => [
            ['label' => 'Home', 'url' => '/', 'order' => 10, 'hidden' => false],
            ['label' => 'Courses', 'url' => '/courses', 'order' => 20, 'hidden' => false],
            ['label' => 'Instructors', 'url' => '/instructors', 'order' => 30, 'hidden' => false],
            ['label' => 'E-Books', 'url' => '/ebooks', 'order' => 40, 'hidden' => false],
            ['label' => 'Products', 'url' => '/products', 'order' => 50, 'hidden' => false],
            ['label' => 'Board Result', 'url' => '/board-result', 'order' => 60, 'hidden' => false],
            ['label' => 'About Us', 'url' => '/about-us', 'order' => 70, 'hidden' => false],
            ['label' => 'Contact Us', 'url' => '/contact-us', 'order' => 80, 'hidden' => false],
        ],
    ];

    public function items(string $location, bool $includeHidden = false): array
    {
        abort_unless(array_key_exists($location, self::DEFAULTS), 404);

        $items = self::DEFAULTS[$location];
        if (Schema::hasTable('system_settings')) {
            $value = SystemSetting::query()->where('key', 'site.navigation.' . $location)->value('value');
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $items = $this->sanitize($decoded);
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        $items = array_map(function (array $item): array {
            $external = str_starts_with($item['url'], 'https://') || str_starts_with($item['url'], 'http://');
            $path = parse_url($item['url'], PHP_URL_PATH) ?: '/';
            $current = trim((string) request()->path(), '/');
            $target = trim($path, '/');
            $active = !$external && ($item['url'] === '/'
                ? $current === ''
                : ($target !== '' && ($current === $target || str_starts_with($current, $target . '/'))));

            return $item + ['active' => $active, 'external' => $external];
        }, $items);

        return $includeHidden ? $items : array_values(array_filter($items, static fn (array $item): bool => !$item['hidden']));
    }

    public function save(string $location, array $items, ?int $userId = null): void
    {
        abort_unless(array_key_exists($location, self::DEFAULTS), 404);

        $safe = $this->sanitize($items);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'site.navigation.' . $location],
            [
                'value' => json_encode($safe, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_by' => $userId,
            ]
        );
    }

    private function sanitize(array $items): array
    {
        $safe = [];
        foreach (array_slice($items, 0, 30) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || mb_strlen($label) > 60 || !$this->isSafeUrl($url)) {
                continue;
            }

            $safe[] = [
                'label' => $label,
                'url' => $url,
                'order' => max(-1000, min(1000, (int) ($item['order'] ?? (($index + 1) * 10)))),
                'hidden' => (bool) ($item['hidden'] ?? false),
            ];
        }

        return $safe;
    }

    public function isSafeUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20]/', $url) || str_contains($url, '\\')) {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '..')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
