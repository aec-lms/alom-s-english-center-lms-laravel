<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class UniversalPaymentServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'universal-payment'; }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
