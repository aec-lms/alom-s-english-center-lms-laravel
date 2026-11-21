<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningRewards\Providers;

use App\Core\Plugins\BasePluginServiceProvider;

class LearningRewardsServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string
    {
        return 'learning-rewards';
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }

    public function register(): void
    {
        $this->app->singleton(\Plugins\Modules\LearningRewards\Services\LearningRewardsService::class);
    }
}
