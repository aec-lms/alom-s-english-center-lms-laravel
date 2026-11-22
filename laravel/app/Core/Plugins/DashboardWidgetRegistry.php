<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use App\Models\PluginRecord;
use Illuminate\Support\Facades\Route;

final class DashboardWidgetRegistry
{
    /** @return array<string, array{label:string,description:string,plugin:string,route:?string}> */
    public function availableFor(?object $user): array
    {
        if (!$user || !method_exists($user, 'hasRole') || $user->hasRole('branch_manager')) {
            return [];
        }

        $available = [];
        $records = PluginRecord::query()
            ->where('is_installed', true)
            ->where('is_enabled', true)
            ->get()
            ->keyBy('slug');

        foreach (app(PluginManager::class)->discoverManifests() as $slug => $manifest) {
            $record = $records->get($slug);
            if (!$record) {
                continue;
            }

            foreach ($manifest->dashboardWidgets as $definition) {
                $permission = $definition['permission'];
                if ($permission !== null
                    && (!method_exists($user, 'hasPermission') || !$user->hasPermission($permission))) {
                    continue;
                }

                $route = $definition['route'];
                if ($route !== null && !Route::has($route)) {
                    continue;
                }

                $id = 'plugin-' . $slug . '-' . $definition['id'];
                $available[$id] = [
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'plugin' => $manifest->name,
                    'route' => $route,
                ];
            }
        }

        return $available;
    }
}
