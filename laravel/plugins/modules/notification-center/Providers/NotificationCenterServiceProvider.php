<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class NotificationCenterServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'notification-center'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
