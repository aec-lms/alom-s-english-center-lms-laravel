<?php

declare(strict_types=1);

namespace Plugins\Modules\Scorm\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class ScormServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'scorm';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function onUninstall(bool $purgePluginData = false): void
    {
        // Package files and learner attempts are retained by default. Explicit purge
        // must be a separately authorized, audited operation after retention checks.
    }
}
