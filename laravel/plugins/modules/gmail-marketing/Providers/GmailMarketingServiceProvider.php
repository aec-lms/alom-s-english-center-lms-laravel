<?php

declare(strict_types=1);

namespace Plugins\Modules\GmailMarketing\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class GmailMarketingServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gmail-marketing'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
