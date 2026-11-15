<?php

declare(strict_types=1);

namespace Plugins\Modules\BatchManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class BatchManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'batch-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
