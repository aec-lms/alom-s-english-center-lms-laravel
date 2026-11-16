<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Contracts\PluginInterface;
use App\Core\Plugins\PluginManifest;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantService;
use Tests\TestCase;

class PluginCriticalRegressionTest extends TestCase
{
    public function test_storefront_provider_uses_the_plugin_runtime_contract(): void
    {
        $manifest = PluginManifest::fromDirectory(base_path('plugins/modules/storefront-catalog'));

        $this->assertSame(
            'Plugins\\Modules\\StorefrontCatalog\\Providers\\StorefrontCatalogServiceProvider',
            $manifest->providerClass
        );
        $this->assertTrue(class_exists($manifest->providerClass));
        $this->assertTrue(is_subclass_of($manifest->providerClass, PluginInterface::class));
    }

    public function test_attendance_plugin_declares_batch_dependency_and_uses_study_batches(): void
    {
        $manifest = PluginManifest::fromDirectory(base_path('plugins/modules/attendance-management'));

        $this->assertContains('batch-management', $manifest->dependencies);
        $migration = file_get_contents(base_path('plugins/modules/attendance-management/Database/Migrations/2026_02_07_000001_create_attendance_records_table.php'));
        $controller = file_get_contents(base_path('plugins/modules/attendance-management/Http/Controllers/AttendanceController.php'));

        $this->assertIsString($migration);
        $this->assertIsString($controller);
        $this->assertStringContainsString("constrained('study_batches')", $migration);
        $this->assertStringContainsString("exists:study_batches,id", $controller);
        $this->assertStringNotContainsString("exists:batches,id", $controller);
    }

    public function test_empty_ai_role_allowlist_denies_every_role(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $user = $this->createUserWithRole('student');

        app(PluginManager::class)->saveSettings('role-ai-assistant', ['allowed_roles' => '']);

        $this->assertFalse(app(AiAssistantService::class)->isRoleAllowed($user));
    }

    public function test_ai_ask_route_requires_the_use_permission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);

        $route = app('router')->getRoutes()->getByName('plugins.ai.ask');

        $this->assertNotNull($route);
        $this->assertContains('permission:ai.assistant.use', $route->gatherMiddleware());
    }
}
