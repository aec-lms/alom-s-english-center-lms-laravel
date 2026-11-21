<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Plugins\PluginManager;
use App\Models\PluginRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class);

        spl_autoload_register(static function (string $class): void {
            $prefix = 'Plugins\\Modules\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $parts = explode('\\', $relative);
            if (count($parts) < 2) {
                return;
            }
            $studlySlug = array_shift($parts);
            $kebabSlug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studlySlug));
            $modulesRoot = (string) config('plugins.modules_path', base_path('plugins/modules'));
            $file = $modulesRoot . DIRECTORY_SEPARATOR . $kebabSlug . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function boot(PluginManager $manager): void
    {
        // MySQL tests rebuild the schema per test and explicitly install the plugins
        // they exercise. Avoid booting providers from stale rows before migrate:fresh.
        if ($this->app->environment('testing') && (bool) config('plugins.explicit_test_boot', false)) {
            return;
        }

        if (!Schema::hasTable('plugins')) {
            return;
        }

        $manifests = $manager->discoverManifests();
        $enabledSlugs = PluginRecord::query()
            ->where('is_installed', true)
            ->where('is_enabled', true)
            ->pluck('slug')
            ->all();

        foreach ($enabledSlugs as $slug) {
            $manifest = $manifests[$slug] ?? null;
            if (!$manifest) {
                continue;
            }

            if ($manifest->providerClass && class_exists($manifest->providerClass)) {
                $provider = $this->app->resolveProvider($manifest->providerClass);
                $this->app->register($provider);
            }

            // The compiled route cache already contains the enabled plugins' routes.
            // Requiring their route files again would register duplicate URI/name entries.
            if (!is_file($this->app->getCachedRoutesPath())) {
                $manager->loadPluginRoutes($manifest);
            }
        }
    }
}
