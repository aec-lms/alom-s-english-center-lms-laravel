<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Contracts\PluginInterface;
use App\Core\Plugins\PluginManifest;
use App\Core\Plugins\PluginManager;
use App\Models\Permission;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
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

    public function test_attendance_plugin_uses_optional_batch_integrations_without_hard_foreign_keys(): void
    {
        $manifest = PluginManifest::fromDirectory(base_path('plugins/modules/attendance-management'));

        $this->assertSame([], $manifest->dependencies);
        $this->assertContains('batch-management', $manifest->optionalDependencies);
        $this->assertContains('course-management', $manifest->optionalDependencies);
        $this->assertContains('student-management', $manifest->optionalDependencies);
        $migration = file_get_contents(base_path('plugins/modules/attendance-management/Database/Migrations/2026_02_07_000001_create_attendance_records_table.php'));
        $controller = file_get_contents(base_path('plugins/modules/attendance-management/Http/Controllers/AttendanceController.php'));

        $this->assertIsString($migration);
        $this->assertIsString($controller);
        $this->assertStringContainsString('$table->unsignedBigInteger(\'batch_id\')->index();', $migration);
        $this->assertStringContainsString("Schema::hasTable('study_batches')", $controller);
        $this->assertStringContainsString("Schema::hasTable('batches')", $controller);
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
        $this->assertStringContainsString('$isInvoiceOwner', $controller);
        $this->assertStringContainsString('$invoice->payer_email', $controller);
        $this->assertStringContainsString("'fee_invoice_id' => ['nullable', 'integer']", $controller);
        $this->assertStringContainsString("Schema::hasTable('fee_invoices')", $controller);
        $this->assertStringNotContainsString("'exists:fee_invoices,id'", $controller);
        $checkoutMethod = substr($controller, strpos($controller, 'public function publicCheckout'));
        $this->assertStringNotContainsString("'amount' => ['required'", $checkoutMethod);
        $this->assertStringContainsString("'enabled_gateways'", $controller);
        $this->assertStringContainsString('assertGatewayReady', $orchestrator);
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
            ->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid', ['payment_reference' => 'BRANCH-REFUSED-001'])
            ->assertForbidden();
        $this->assertSame('pending', $payroll->fresh()->status);

        $this->actingAs($admin)
            ->post('/admin/hr/payrolls/' . $payroll->id . '/mark-paid', ['payment_reference' => 'ADMIN-DISBURSEMENT-001'])
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
        $migration = file_get_contents(base_path('plugins/modules/universal-payment/Database/Migrations/2026_11_16_000006_add_branch_id_to_universal_payment_transactions.php'));
        $paymentController = file_get_contents(base_path('plugins/modules/payment-management/Http/Controllers/PaymentController.php'));

        $this->assertIsString($controller);
        $this->assertIsString($paymentController);
        $refundRegistry = file_get_contents(base_path('plugins/modules/payment-management/Services/RefundProviderRegistry.php'));
        $this->assertIsString($refundRegistry);
        $this->assertStringContainsString('getSetting($gateway, \'refunds_enabled\', \'0\') !== \'1\'', $refundRegistry);
        $this->assertStringContainsString('Provider refunds are disabled until merchant sandbox and refund capability are verified.', $refundRegistry);
        $this->assertStringNotContainsString('refundOnline', $paymentController);
        $this->assertIsString($migration);
        $this->assertStringContainsString("'status' => 'pending'", $controller);
        $this->assertStringContainsString("'external_trx_id' => ['required', 'string', 'max:120']", $controller);
        $this->assertStringContainsString("A bank receipt or cash voucher reference is required before verification.", $controller);
        $this->assertStringContainsString("'in:verified,rejected'", $controller);
        $this->assertStringContainsString("Only pending manual receipts can be reviewed.", $controller);
        $this->assertStringContainsString("verified provider callback", $controller);
        $this->assertStringContainsString('$actor->branch_id', $controller);
        $this->assertStringContainsString("branch_id", $migration);
        $this->assertStringNotContainsString("'in:pending,verified,refunded'", $controller);
    }

    public function test_notification_center_scopes_branch_managers_to_recipient_branch(): void
    {
        $controller = file_get_contents(base_path('plugins/modules/notification-center/Http/Controllers/NotificationController.php'));
        $migration = file_get_contents(base_path('plugins/modules/notification-center/Database/Migrations/2026_11_16_000001_add_branch_id_to_lms_notifications.php'));

        $this->assertIsString($controller);
        $this->assertIsString($migration);
        $this->assertStringContainsString('$this->branchUserIds((int) $actor->branch_id)', $controller);
        $this->assertStringContainsString('$recipientBranchId = app(BranchAccess::class)->contextBranchId($recipient);', $controller);
        $this->assertStringContainsString('abort_unless($recipientBranchId !== null && $recipientBranchId === (int) $actor->branch_id, 404);', $controller);
        $this->assertStringContainsString("'branch_id' => \$recipientBranchId", $controller);
        $this->assertStringContainsString("branch_id", $migration);
    }

    public function test_notification_delivery_job_confirms_email_only_after_mail_transport_and_marks_unsupported_channels_failed(): void
    {
        $job = file_get_contents(base_path('plugins/modules/notification-center/Jobs/DeliverNotificationJob.php'));
        $migration = file_get_contents(base_path('plugins/modules/notification-center/Database/Migrations/2026_11_16_000002_add_notification_delivery_tracking.php'));
        $controller = file_get_contents(base_path('plugins/modules/notification-center/Http/Controllers/NotificationController.php'));

        $this->assertIsString($job);
        $this->assertIsString($migration);
        $this->assertIsString($controller);
        $this->assertStringContainsString('implements ShouldQueue', $job);
        $this->assertStringContainsString('Mail::mailer(\'smtp\')->raw', $job);
        $this->assertStringContainsString("'status' => 'sent'", $job);
        $this->assertStringContainsString('No verified delivery adapter is configured for this channel.', $job);
        $this->assertStringContainsString('DeliverNotificationJob::dispatch', $controller);
        $this->assertStringContainsString('attempt_count', $migration);
        $this->assertStringContainsString('last_error', $migration);
        $this->assertStringContainsString('processed_at', $migration);
    }

    public function test_database_queue_configuration_and_tables_exist_for_notification_workers(): void
    {
        $queueConfig = file_get_contents(base_path('config/queue.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_11_16_000005_create_database_queue_tables.php'));

        $this->assertIsString($queueConfig);
        $this->assertIsString($migration);
        $this->assertStringContainsString("env('QUEUE_CONNECTION', 'database')", $queueConfig);
        $this->assertStringContainsString("'driver' => 'database'", $queueConfig);
        $this->assertStringContainsString("Schema::create('jobs'", $migration);
        $this->assertStringContainsString("Schema::create('job_batches'", $migration);
        $this->assertStringContainsString("Schema::create('failed_jobs'", $migration);
    }

    public function test_notification_delivery_job_is_unique_per_notification_to_prevent_duplicate_dispatch(): void
    {
        $job = new DeliverNotificationJob(481);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('481', $job->uniqueId());
        $this->assertSame(3600, $job->uniqueFor);
    }

    public function test_notification_email_job_marks_sent_only_after_mail_transport_returns(): void
    {
        $this->markSystemInstalled();
        Schema::create('lms_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('channel', 24);
            $table->string('title', 180);
            $table->text('message');
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        $recipient = $this->createUserWithRole('student', null, 'notification-recipient@aec.test');
        $notification = LmsNotification::query()->create([
            'user_id' => $recipient->id,
            'channel' => 'email',
            'title' => 'Test notification',
            'message' => 'Test body',
            'status' => 'queued',
        ]);

        $plugins = \Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('isEnabled')->with('email-notifications')->andReturn(true);
        $plugins->shouldReceive('getSetting')->with('email-notifications', 'smtp_host', '')->andReturn('smtp.example.test');
        $plugins->shouldReceive('getSetting')->with('email-notifications', 'smtp_port', '587')->andReturn(587);
        $plugins->shouldReceive('getSetting')->with('email-notifications', 'smtp_username', '')->andReturn('smtp-user');
        $plugins->shouldReceive('getSetting')->with('email-notifications', 'smtp_password', '')->andReturn('smtp-secret');
        $plugins->shouldReceive('getSetting')->withArgs(fn ($slug, $key) => $slug === 'email-notifications' && $key === 'from_address')->andReturn('noreply@aec.test');

        $mailer = \Mockery::mock();
        $mailer->shouldReceive('raw')->once()->with('Test body', \Mockery::type('Closure'))->andReturnNull();
        Mail::shouldReceive('purge')->once()->with('smtp');
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);

        (new DeliverNotificationJob($notification->id))->handle($plugins);

        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->attempt_count);
        $this->assertNotNull($notification->processed_at);
        $this->assertNull($notification->last_error);
    }

    public function test_notification_email_job_does_not_mark_unsupported_channels_as_sent(): void
    {
        $this->markSystemInstalled();
        Schema::create('lms_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('channel', 24);
            $table->string('title', 180);
            $table->text('message');
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        $recipient = $this->createUserWithRole('student');
        $notification = LmsNotification::query()->create([
            'user_id' => $recipient->id,
            'channel' => 'sms',
            'title' => 'SMS test',
            'message' => 'Test body',
            'status' => 'queued',
        ]);

        $plugins = \Mockery::mock(PluginManager::class);
        (new DeliverNotificationJob($notification->id))->handle($plugins);

        $notification->refresh();
        $this->assertSame('failed', $notification->status);
        $this->assertSame('No verified delivery adapter is configured for this channel.', $notification->last_error);
        $this->assertSame(1, $notification->attempt_count);
    }


}
