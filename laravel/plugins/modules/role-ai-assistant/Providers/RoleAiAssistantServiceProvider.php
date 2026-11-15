<?php

declare(strict_types=1);

namespace Plugins\Modules\RoleAiAssistant\Providers;

use App\Core\Plugins\BasePluginServiceProvider;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantService;

class RoleAiAssistantServiceProvider extends BasePluginServiceProvider
{
    public function slug(): string { return 'role-ai-assistant'; }

    public function register(): void
    {
        $this->app->singleton(AiAssistantService::class);
    }

    protected function pluginPath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR) : '');
    }
}
