<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\PaymentManagement\Services\RefundOrchestrationService;
use Plugins\Modules\PaymentManagement\Services\RefundProviderRegistry;
use Plugins\Modules\PaymentManagement\Services\RefundSettlementService;

class PaymentManagementServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'payment-management'; }

    public function register(): void
    {
        $this->app->singleton(PaymentSettlementService::class);
        $this->app->singleton(RefundProviderRegistry::class);
        $this->app->singleton(RefundSettlementService::class);
        $this->app->singleton(RefundOrchestrationService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
