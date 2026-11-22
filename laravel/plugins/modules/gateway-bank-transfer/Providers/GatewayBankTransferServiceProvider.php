<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayBankTransfer\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

final class GatewayBankTransferServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'gateway-bank-transfer';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
