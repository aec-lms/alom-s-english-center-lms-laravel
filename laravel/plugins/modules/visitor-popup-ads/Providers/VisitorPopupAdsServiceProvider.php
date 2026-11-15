<?php

declare(strict_types=1);

namespace Plugins\Modules\VisitorPopupAds\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class VisitorPopupAdsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'visitor-popup-ads'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
