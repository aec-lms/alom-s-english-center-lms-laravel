<?php

declare(strict_types=1);

namespace Plugins\\Modules\\BoardQuestionSolver\\Providers;

use App\\Core\\Plugins\\BasePluginServiceProvider;

class BoardQuestionSolverServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'board-question-solver';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
