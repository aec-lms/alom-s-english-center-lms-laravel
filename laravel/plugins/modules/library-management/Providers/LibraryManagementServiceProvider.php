<?php

declare(strict_types=1);

namespace Plugins\Modules\LibraryManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class LibraryManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'library-management'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
