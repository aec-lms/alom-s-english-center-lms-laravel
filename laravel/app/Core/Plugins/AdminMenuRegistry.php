<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use Illuminate\Support\Facades\Route;

final class AdminMenuRegistry
{
    /**
     * Build a safe, permission-filtered menu from enabled plugin manifests.
     *
     * Each manifest may declare:
     * "admin_menu": [{"group":"Academic","label":"Courses","route":"plugins.courses.index","permission":"courses.manage","order":10}]
     *
     * @return array<string, array<int, array{label:string,route:string,permission:?string,plugin:string,order:int}>>
     */
    public function groups(): array
    {
        $groups = [];
        $manager = app(PluginManager::class);
        $user = auth()->user();\n        $enabled = array_fill_keys(PluginRecord::query()->where('is_installed', true)->where('is_enabled', true)->pluck('slug')->all(), true);

        if (!$user) {
            return [];
        }

        foreach ($manager->discoverManifests() as $slug => $manifest) {
            if (!isset($enabled[$slug])) {
                continue;
            }

            foreach ($manifest->adminMenu as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $group = trim((string) ($entry['group'] ?? 'Plugins'));
                $label = trim((string) ($entry['label'] ?? ''));
                $route = trim((string) ($entry['route'] ?? ''));
                $permission = isset($entry['permission']) ? trim((string) $entry['permission']) : null;

                if ($label === '' || $route === '' || !Route::has($route)) {
                    continue;
                }

                if ($permission !== null && $permission !== '' && method_exists($user, 'can') && !$user->can($permission)) {
                    continue;
                }

                $groups[$group][] = [
                    'label' => $label,
                    'route' => $route,
                    'permission' => $permission,
                    'plugin' => $slug,
                    'order' => (int) ($entry['order'] ?? 100),
                ];
            }
        }

        foreach ($groups as &$items) {
            usort($items, static fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
        }
        unset($items);

        ksort($groups);

        return $groups;
    }
}
