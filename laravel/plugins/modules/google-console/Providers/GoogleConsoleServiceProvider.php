<?php

declare(strict_types=1);

namespace Plugins\Modules\GoogleConsole\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GoogleConsoleServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'google-console'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
