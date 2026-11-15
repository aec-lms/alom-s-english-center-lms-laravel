<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CourseManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'course-management'; }
    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
