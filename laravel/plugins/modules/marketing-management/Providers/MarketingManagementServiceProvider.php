<?php

declare(strict_types=1);

namespace Plugins\Modules\MarketingManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class MarketingManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'marketing-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
