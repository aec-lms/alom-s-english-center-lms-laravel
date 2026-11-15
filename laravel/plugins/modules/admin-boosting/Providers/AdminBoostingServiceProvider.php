<?php

declare(strict_types=1);

namespace Plugins\Modules\AdminBoosting\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class AdminBoostingServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'admin-boosting'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
