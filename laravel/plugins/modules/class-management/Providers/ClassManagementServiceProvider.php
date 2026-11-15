<?php

declare(strict_types=1);

namespace Plugins\Modules\ClassManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class ClassManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'class-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
