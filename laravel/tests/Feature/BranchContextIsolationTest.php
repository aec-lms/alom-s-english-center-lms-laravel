<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Tests\TestCase;

class BranchContextIsolationTest extends TestCase
{
    public function test_active_branch_manager_can_use_authenticated_api_but_inactive_branch_is_denied_globally(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'mobile-app-api']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'CTX-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Context Test Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $token = $manager->createToken('branch-context-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        DB::table('branches')->where('id', $branchId)->update(['is_active' => false]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_reports_do_not_disclose_global_plugin_inventory_to_branch_manager(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'reports-analytics']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'RPT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Report Scope Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $permissionId = DB::table('permissions')->where('slug', 'reports.view')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($manager)->get('/admin/reports')
            ->assertOk()
            ->assertSee('Restricted');
    }
    public function test_queued_notification_cannot_be_delivered_across_branch_ownership(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'notification-center']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'NTA-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Notification Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'NTB-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Notification Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $recipient = $this->createUserWithRole('student', $branchB);

        $notificationId = DB::table('lms_notifications')->insertGetId([
            'user_id' => $recipient->id,
            'branch_id' => $branchA,
            'channel' => 'in_app',
            'title' => 'Branch isolation test',
            'message' => 'This must not be delivered across branches.',
            'status' => 'queued',
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new DeliverNotificationJob($notificationId))->handle(app(PluginManager::class));

        $this->assertDatabaseHas('lms_notifications', [
            'id' => $notificationId,
            'status' => 'failed',
            'last_error' => 'Notification branch does not match the recipient account branch.',
        ]);
    }

}
