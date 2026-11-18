<?php

declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Providers;

use Illuminate\Support\ServiceProvider;
use Plugins\Modules\Phonetics\Services\CourseCatalog;

/**
 * Standalone provider for installing the Phonetics package in a Laravel LMS
 * that does not use the AEC plugin manager.
 */
final class StandalonePhoneticsServiceProvider extends ServiceProvider
{
    private function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__).($relative !== '' ? DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function register(): void
    {
        $hostMiddleware = config('phonetics.middleware');
        $this->mergeConfigFrom($this->pluginPath('Config/phonetics.php'), 'phonetics');
        $this->app->singleton(CourseCatalog::class);

        if (config('phonetics.standalone') && !$hostMiddleware) {
            config([
                'phonetics.middleware.practice' => ['web'],
                'phonetics.middleware.attempt' => ['web', 'auth'],
                'phonetics.middleware.exam' => ['web', 'auth'],
                'phonetics.middleware.admin' => ['web', 'auth', 'can:manage-phonetics'],
            ]);
        }
    }

    public function boot(): void
    {
        $routes = $this->pluginPath('Routes/web.php');
        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }
        $views = $this->pluginPath('Resources/views');
        if (is_dir($views)) {
            $this->loadViewsFrom($views, 'phonetics');
        }
        $migrations = $this->pluginPath('Database/Migrations');
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
