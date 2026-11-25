<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\UniversalPayment\Services\UniversalPaymentOrchestrator;

class UniversalPaymentServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'universal-payment'; }

    public function register(): void
    {
        $this->app->singleton(PaymentGatewayRegistry::class);
        $this->app->singleton(UniversalPaymentOrchestrator::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
