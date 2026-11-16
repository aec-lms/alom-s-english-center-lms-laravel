<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Contracts\PluginInterface;
use App\Core\Plugins\PluginManifest;
use App\Core\Plugins\PluginManager;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\HrStaffManagement\Models\StaffPayroll;
use Plugins\Modules\HrStaffManagement\Models\StaffProfile;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantService;
use Plugins\Modules\VisitorPopupAds\Models\VisitorPopup;
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
        $checkoutMethod = substr($controller, strpos($controller, 'public function publicCheckout'));
        $this->assertStringNotContainsString("'amount' => ['required'", $checkoutMethod);
        $this->assertStringContainsString("'enabled_gateways'", $orchestrator);
    }

    public function test_payroll_is_pending_until_admin_confirms_disbursement(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'hr-staff-management']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'HR-TEST',
            'name' => 'HR Test Branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = StaffProfile::query()->create([
            'branch_id' => $branchId,
            'employee_code' => 'HR-EMP-001',
            'full_name' => 'Test Staff',
            'designation' => 'Teacher',
            'department' => 'Academic',
            'base_salary' => 25000,
            'status' => 'active',
        ]);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/hr/payrolls', [
            'staff_profile_id' => $staff->id,
            'payroll_month' => '2026-11',
            'bonus' => 1000,
            'deductions' => 500,
        ])->assertSessionHasNoErrors();

        $payroll = StaffPayroll::query()->firstOrFail();
        $this->assertSame('pending', $payroll->status);
        $this->assertNull($payroll->paid_at);

        $branchManager = $this->createUserWithRole('branch_manager', $branchId);
        $hrPermission = Permission::query()->where('slug', 'hr.manage')->firstOrFail();
        $branchManager->roles()->first()->permissions()->syncWithoutDetaching([$hrPermission->id]);

        $this->actingAs($branchManager)
            ->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid')
            ->assertForbidden();

        $this->actingAs($admin)
            ->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid')
            ->assertSessionHas('status');

        $this->assertSame('paid', $payroll->fresh()->status);
        $this->assertNotNull($payroll->fresh()->paid_at);
    }

    public function test_popup_admin_can_edit_all_content_and_unsafe_destinations_are_rejected(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['visitor-popup-ads']);
        $admin = $this->createUserWithRole('admin');
        $popup = VisitorPopup::query()->create([
            'title' => 'Original Offer',
            'badge_text' => 'OLD',
            'description' => 'Old description',
            'image_path' => '/uploads/popups/old.jpg',
            'cta_text' => 'Old CTA',
            'cta_url' => '/courses',
            'delay_seconds' => 3,
            'frequency' => 'once_per_session',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/plugins/visitor-popups/' . $popup->id, [
            'title' => 'Updated IELTS Offer',
            'badge_text' => 'NEW OFFER',
            'description' => 'Updated description and promotion details',
            'cta_text' => 'Join Today',
            'cta_url' => '/ielts-admission',
            'image_url' => 'https://example.test/promo.webp',
            'delay_seconds' => 8,
            'frequency' => 'once_per_day',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visitor_popups', [
            'id' => $popup->id,
            'title' => 'Updated IELTS Offer',
            'badge_text' => 'NEW OFFER',
            'description' => 'Updated description and promotion details',
            'image_path' => 'https://example.test/promo.webp',
            'cta_text' => 'Join Today',
            'cta_url' => '/ielts-admission',
            'delay_seconds' => 8,
            'frequency' => 'once_per_day',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/plugins/visitor-popups/' . $popup->id, [
            'title' => 'Unsafe Destination Test',
            'cta_url' => 'javascript:alert(1)',
            'delay_seconds' => 8,
            'frequency' => 'always',
        ])->assertSessionHasErrors('cta_url');
    }

    public function test_universal_payment_manual_records_cannot_self_verify_or_refund_and_are_branch_scoped(): void
    {
        $controller = file_get_contents(base_path('plugins/modules/universal-payment/Http/Controllers/UniversalPaymentController.php'));
        $migration = file_get_contents(base_path('plugins/modules/universal-payment/Database/Migrations/2026_11_16_000001_add_branch_id_to_universal_payment_transactions.php'));

        $this->assertIsString($controller);
        $this->assertIsString($migration);
        $this->assertStringContainsString("'status' => 'pending'", $controller);
        $this->assertStringContainsString("'in:verified,rejected'", $controller);
        $this->assertStringContainsString("Only pending transactions can be reviewed.", $controller);
        $this->assertStringContainsString("verified provider callbacks", $controller);
        $this->assertStringContainsString("where('branch_id', $user->branch_id ?? 0)", $controller);
        $this->assertStringContainsString("branch_id", $migration);
        $this->assertStringNotContainsString("'in:pending,verified,refunded'", $controller);
    }

}
