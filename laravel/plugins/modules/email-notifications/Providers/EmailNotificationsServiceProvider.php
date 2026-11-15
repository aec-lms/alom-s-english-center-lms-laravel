<?php

declare(strict_types=1);

namespace Plugins\Modules\EmailNotifications\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class EmailNotificationsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'email-notifications'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
