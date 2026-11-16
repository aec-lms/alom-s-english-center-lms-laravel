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

    public function test_ai_ask_route_declares_the_use_permission(): void
    {
        $routeFile = file_get_contents(base_path('plugins/modules/role-ai-assistant/Routes/web.php'));

        $this->assertIsString($routeFile);
        $this->assertStringContainsString("Route::post('/ask'", $routeFile);
        $this->assertStringContainsString("->middleware('permission:ai.assistant.use')", $routeFile);
    }
    public function test_universal_payment_checkout_is_authenticated_rate_limited_and_invoice_bound(): void
    {
        $routeFile = file_get_contents(base_path('plugins/modules/universal-payment/Routes/web.php'));
        $controller = file_get_contents(base_path('plugins/modules/universal-payment/Http/Controllers/UniversalPaymentController.php'));
        $orchestrator = file_get_contents(base_path('plugins/modules/universal-payment/Services/UniversalPaymentOrchestrator.php'));

        $this->assertIsString($routeFile);
        $this->assertIsString($controller);
        $this->assertIsString($orchestrator);
        $this->assertStringContainsString("'auth'", $routeFile);
        $this->assertStringContainsString("'throttle:10,1'", $routeFile);
        $this->assertStringContainsString("whereHas('student'", $controller);
        $this->assertStringContainsString("'exists:fee_invoices,id'", $controller);
        $this->assertStringNotContainsString("'amount' => ['required'", $controller);
        $this->assertStringContainsString("'enabled_gateways'", $orchestrator);
    }

}
