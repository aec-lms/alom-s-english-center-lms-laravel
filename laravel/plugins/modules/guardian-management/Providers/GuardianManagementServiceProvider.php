<?php

declare(strict_types=1);

namespace Plugins\Modules\GuardianManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GuardianManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'guardian-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
