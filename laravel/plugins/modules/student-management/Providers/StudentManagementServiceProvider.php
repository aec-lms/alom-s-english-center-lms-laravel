<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class StudentManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'student-management'; }
    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
