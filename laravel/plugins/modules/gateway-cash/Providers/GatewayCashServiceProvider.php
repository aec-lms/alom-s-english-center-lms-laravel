<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCash\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;

final class GatewayCashServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-cash'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-cash', 'Cash Desk Payment Method', [], static function (array $input): array {
                throw new \RuntimeException('Manual payment submissions are recorded and settled through the Universal Payment review workflow.');
            }, false);
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
