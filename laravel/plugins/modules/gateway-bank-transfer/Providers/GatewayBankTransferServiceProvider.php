<?php

declare(strict_types=1);

namespace Plugins\\Modules\\GatewayBankTransfer\\Providers;

use Illuminate\\Support\\ServiceProvider;

final class GatewayBankTransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Payment method configuration is consumed by Universal Payment.
    }
}
