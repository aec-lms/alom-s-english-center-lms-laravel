<?php

declare(strict_types=1);

namespace Plugins\\Modules\\ExpenseManagement\\Providers;

use App\\Core\\Plugins\\BasePluginServiceProvider;

class ExpenseManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'expense-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
