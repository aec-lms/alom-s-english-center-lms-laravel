<?php

declare(strict_types=1);

namespace Plugins\Modules\FlashSale\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\FlashSale\Services\FlashSalePricingService;

class FlashSaleServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'flash-sale'; }

    public function register(): void
    {
        $this->app->singleton(FlashSalePricingService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
