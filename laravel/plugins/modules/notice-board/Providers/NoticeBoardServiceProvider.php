<?php

declare(strict_types=1);

namespace Plugins\Modules\NoticeBoard\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class NoticeBoardServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'notice-board'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
