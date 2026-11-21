<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningPaths\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class LearningPathsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'learning-paths';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
