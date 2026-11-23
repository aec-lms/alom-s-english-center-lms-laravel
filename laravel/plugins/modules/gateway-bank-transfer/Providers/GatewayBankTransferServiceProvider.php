<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayBankTransfer\Providers;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\BasePluginServiceProvider;

final class GatewayBankTransferServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'gateway-bank-transfer'; }

    public function boot(): void
    {
        parent::boot();
        $this->app->afterResolving(PaymentGatewayRegistry::class, function (PaymentGatewayRegistry $registry): void {
            $registry->register('gateway-bank-transfer', 'Bank Transfer Payment Method', [], static function (array $input): array {
                throw new \RuntimeException('Manual payment submissions are recorded and settled through the Universal Payment review workflow.');
            }, false);
        });
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
