<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use App\Core\Contracts\PluginInterface;
use Illuminate\Support\ServiceProvider;

abstract class BasePluginServiceProvider extends ServiceProvider implements PluginInterface
{
    abstract public function slug(): string;

    abstract protected function pluginPath(string $relative = ''): string;

    public function register(): void
    {
        // Optional bindings
    }

    public function boot(): void
    {
        $views = $this->pluginPath('Resources/views');
        if (is_dir($views)) {
            $this->loadViewsFrom($views, $this->slug());
        }

        $translations = $this->pluginPath('lang');
        if (is_dir($translations)) {
            $this->loadJsonTranslationsFrom($translations);
            $this->loadTranslationsFrom($translations, 'plugins.' . $this->slug());
        }

        $migrations = $this->pluginPath('Database/Migrations');
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }
    }

    public function onInstall(): void {}

    public function onEnable(): void {}

    public function onDisable(): void {}

    public function onUninstall(bool $purgePluginData = false): void {}
}
