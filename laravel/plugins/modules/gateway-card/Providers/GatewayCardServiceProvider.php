<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCard\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;

class GatewayCardServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-card'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-card', 'Visa / Mastercard Acquirer Adapter', ["merchant_id","api_secret","webhook_secret"], function (array $input): array {
                throw new \RuntimeException('A bank/acquirer-specific card adapter must be installed before card checkout can be enabled.');
            }, false);
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
