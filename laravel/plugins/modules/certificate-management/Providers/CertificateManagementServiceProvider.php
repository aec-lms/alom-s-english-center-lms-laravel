<?php

declare(strict_types=1);

namespace Plugins\Modules\CertificateManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CertificateManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'certificate-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
