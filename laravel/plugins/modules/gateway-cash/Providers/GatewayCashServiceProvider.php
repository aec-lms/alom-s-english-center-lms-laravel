<?php

declare(strict_types=1);

namespace Plugins\\Modules\\GatewayCash\\Providers;

use Illuminate\\Support\\ServiceProvider;

final class GatewayCashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Payment method configuration is consumed by Universal Payment.
    }
}
