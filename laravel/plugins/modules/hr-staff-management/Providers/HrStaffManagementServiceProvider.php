<?php

declare(strict_types=1);

namespace Plugins\Modules\HrStaffManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class HrStaffManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'hr-staff-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
