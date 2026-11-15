<?php

declare(strict_types=1);

namespace App\Core\Contracts;

interface PluginInterface
{
    public function slug(): string;

    public function register(): void;

    public function boot(): void;

    public function onInstall(): void;

    public function onEnable(): void;

    public function onDisable(): void;

    public function onUninstall(bool $purgePluginData = false): void;
}
