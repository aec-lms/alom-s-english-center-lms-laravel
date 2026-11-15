<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class MobileAppApiServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'mobile-app-api'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
