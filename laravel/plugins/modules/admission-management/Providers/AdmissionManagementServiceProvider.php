<?php

declare(strict_types=1);

namespace Plugins\Modules\AdmissionManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class AdmissionManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'admission-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
