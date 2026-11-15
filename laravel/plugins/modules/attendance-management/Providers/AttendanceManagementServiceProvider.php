<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class AttendanceManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'attendance-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
