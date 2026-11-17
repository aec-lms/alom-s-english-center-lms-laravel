<?php

declare(strict_types=1);

namespace Plugins\Modules\MediaLibrary\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class MediaLibraryServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'media-library'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}