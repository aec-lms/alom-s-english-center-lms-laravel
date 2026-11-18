<?php

declare(strict_types=1);

namespace Plugins\Modules\PushNotifications\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class PushNotificationsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'push-notifications';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__).($relative !== '' ? DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
