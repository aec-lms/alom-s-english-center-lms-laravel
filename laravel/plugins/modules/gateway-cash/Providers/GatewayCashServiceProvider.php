<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCash\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

final class GatewayCashServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'gateway-cash';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
