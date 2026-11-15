<?php

declare(strict_types=1);

namespace Plugins\Modules\TeacherManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class TeacherManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'teacher-management'; }
    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
