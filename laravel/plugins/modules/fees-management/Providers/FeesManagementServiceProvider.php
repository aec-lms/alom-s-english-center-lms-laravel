<?php

declare(strict_types=1);

namespace Plugins\Modules\FeesManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class FeesManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'fees-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
