<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CourseAssessmentsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'course-assessments'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
