<?php

declare(strict_types=1);

namespace Plugins\Modules\QuizContest\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class QuizContestServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'quiz-contest';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
