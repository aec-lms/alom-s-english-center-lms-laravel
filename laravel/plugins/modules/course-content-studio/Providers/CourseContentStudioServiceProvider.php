<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CourseContentStudioServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'course-content-studio';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
