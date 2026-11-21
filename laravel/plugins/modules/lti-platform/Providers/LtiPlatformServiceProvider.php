<?php

declare(strict_types=1);

namespace Plugins\Modules\LtiPlatform\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\LtiPlatform\Services\LtiPlatformService;

class LtiPlatformServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'lti-platform';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function register(): void
    {
        $this->app->singleton(LtiPlatformService::class);
    }
}
