<?php

declare(strict_types=1);

namespace Plugins\Modules\ReferralIncome\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;

class ReferralIncomeServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'referral-income'; }

    public function register(): void
    {
        $this->app->singleton(ReferralIncomeService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}