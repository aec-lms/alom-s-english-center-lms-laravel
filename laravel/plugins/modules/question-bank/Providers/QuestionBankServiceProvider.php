<?php

declare(strict_types=1);

namespace Plugins\Modules\QuestionBank\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class QuestionBankServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'question-bank'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
