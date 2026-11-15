<?php

declare(strict_types=1);

namespace Plugins\Modules\SmsGateway\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class SmsGatewayServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'sms-gateway'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
