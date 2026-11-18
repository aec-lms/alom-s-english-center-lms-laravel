<?php

declare(strict_types=1);

namespace Plugins\Modules\AssignmentManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class AssignmentManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'assignment-management';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
