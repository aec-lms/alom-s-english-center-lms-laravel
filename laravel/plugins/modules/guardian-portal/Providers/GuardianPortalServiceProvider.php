<?php

declare(strict_types=1);

namespace Plugins\Modules\GuardianPortal\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GuardianPortalServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'guardian-portal';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__).($relative !== '' ? DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
