<?php

declare(strict_types=1);

namespace Plugins\Modules\ReportsAnalytics\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class ReportsAnalyticsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'reports-analytics'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
