<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use App\Models\PluginRecord;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Route;

final class AdminMenuRegistry
{
    /**
     * Build a permission-filtered navigation tree from enabled plugin manifests.
     *
     * @return array<string, array<int, array{label:string,route:string,permission:?string,plugin:string,order:int}>>
     */
    public function groups(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [];
        }

        $enabled = array_fill_keys(
            PluginRecord::query()
                ->where('is_installed', true)
                ->where('is_enabled', true)
                ->pluck('slug')
                ->all(),
            true
        );

        $groups = [];
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();
        foreach (app(PluginManager::class)->discoverManifests() as $slug => $manifest) {
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

                if ($group === '' || $label === '' || $route === '' || !$routes->getByName($route)) {
                    continue;
                }

                if ($permission !== null && $permission !== '') {
                    $allowed = method_exists($user, 'hasPermission')
                        ? $user->hasPermission($permission)
                        : $user->can($permission);
                    if (!$allowed) {
                        continue;
                    }
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

        $preferences = SystemSetting::query()->where('key', 'admin.navigation.menu')->value('value');
        if (is_string($preferences) && $preferences !== '') {
            try {
                $saved = json_decode($preferences, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($saved)) {
                    $regrouped = [];
                    foreach ($groups as $items) {
                        foreach ($items as $item) {
                            $key = $item['plugin'] . '|' . $item['route'];
                            $preference = $saved[$key] ?? [];
                            if ((bool) ($preference['hidden'] ?? false)) {
                                continue;
                            }
                            if (isset($preference['order']) && is_numeric($preference['order'])) {
                                $item['order'] = (int) $preference['order'];
                            }
                            if (isset($preference['group']) && is_string($preference['group']) && trim($preference['group']) !== '') {
                                $item['group'] = trim($preference['group']);
                            }
                            $regrouped[$item['group']][] = $item;
                        }
                    }
                    $groups = $regrouped;
                }
            } catch (\\JsonException) {
                // Invalid saved settings must never break Admin navigation.
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
