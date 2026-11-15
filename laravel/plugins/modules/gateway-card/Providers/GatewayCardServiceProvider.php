<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCard\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\GatewayCard\Services\CardGatewayService;

class GatewayCardServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-card'; }

    public function register(): void
    {
        $this->app->singleton(CardGatewayService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
