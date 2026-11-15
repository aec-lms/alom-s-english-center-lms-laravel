<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class CmsWebsiteServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'cms-website'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
