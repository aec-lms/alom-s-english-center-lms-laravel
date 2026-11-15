<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class ExamManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'exam-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
