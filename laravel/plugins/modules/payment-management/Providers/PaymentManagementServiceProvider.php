<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;

class PaymentManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'payment-management'; }

    public function register(): void
    {
        $this->app->singleton(PaymentSettlementService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
