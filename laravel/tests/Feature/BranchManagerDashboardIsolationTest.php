<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchManagerDashboardIsolationTest extends TestCase
{
    public function test_branch_manager_only_sees_branch_user_count_and_cannot_select_global_widgets(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'reports-analytics', 'notification-center']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'DASH-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Dashboard Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'DASH-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Dashboard Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $managerB = $this->createUserWithRole('branch_manager', $branchB);
        $studentA = $this->createUserWithRole('student', $branchB);
        $studentB = $this->createUserWithRole('student', $branchB);

        DB::table('students')->insert([
            'user_id' => $studentA->id,
            'branch_id' => $branchA,
            'student_code' => 'DASH-ST-A-' . strtoupper(uniqid()),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'user_id' => $studentB->id,
            'branch_id' => $branchB,
            'student_code' => 'DASH-ST-B-' . strtoupper(uniqid()),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'core.dashboard.view')->value('id');
        $reportsPermissionId = DB::table('permissions')->where('slug', 'reports.view')->value('id');
        $notificationsPermissionId = DB::table('permissions')->where('slug', 'notifications.manage')->value('id');
        $this->assertNotNull($permissionId);
        $this->assertNotNull($reportsPermissionId);
        $this->assertNotNull($notificationsPermissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId, $reportsPermissionId, $notificationsPermissionId]);
        $managerB->roles()->first()->permissions()->syncWithoutDetaching([$permissionId, $reportsPermissionId, $notificationsPermissionId]);

        SystemSetting::query()->updateOrCreate(
            ['key' => 'admin.dashboard.widgets.user.' . $manager->id],
            ['value' => json_encode(['users', 'plugins', 'backups', 'audit_trail', 'system_info']), 'is_encrypted' => false]
        );

        AuditLog::query()->create([
            'user_id' => $studentA->id,
            'action' => 'student.updated',
            'entity_type' => 'student',
            'entity_id' => 'profile-' . $studentA->id,
            'ip_address' => '127.0.0.1',
            'metadata' => [],
        ]);

        $response = $this->actingAs($manager)->get('/admin')->assertOk();
        $response->assertSee('Registered Accounts')
            ->assertDontSee('Plugin Overview')
            ->assertDontSee('Encrypted Backups')
            ->assertDontSee('Recent Security Audit Trail')
            ->assertDontSee('System Information');

        preg_match('/<section data-dashboard-widget="users"[^>]*>.*?<span[^>]*data-live-metric="users"[^>]*>(\\d+)<\\/span>/s', $response->getContent(), $matches);
        $this->assertSame('2', $matches[1] ?? null, 'Dashboard count must include only accounts from the assigned branch.');

        $refresh = $this->actingAs($manager)->getJson('/admin/dashboard/refresh')->assertOk();
        $this->assertSame(2, $refresh->json('metrics.users'), 'Live refresh must preserve branch-scoped account counts.');
        $this->assertSame(1, $refresh->json('metrics.students'), 'Live refresh must preserve branch-scoped student counts.');
        $this->assertSame('no-store, private', $refresh->headers->get('Cache-Control'));

        $responseB = $this->actingAs($managerB)->get('/admin')->assertOk();
        preg_match('/<section data-dashboard-widget="users"[^>]*>.*?<span[^>]*data-live-metric="users"[^>]*>(\\d+)<\\/span>/s', $responseB->getContent(), $matchesB);
        $this->assertSame('2', $matchesB[1] ?? null, 'Branch B must count its student profile, not a student whose account branch is stale.');

        $this->actingAs($manager)->get('/admin/reports')->assertOk()->assertViewHas('metrics', function (array $metrics): bool {
            return $metrics['users_count'] === 2 && $metrics['students_count'] === 1 && $metrics['audit_events'] === 1;
        });
        $this->actingAs($managerB)->get('/admin/reports')->assertOk()->assertViewHas('metrics', function (array $metrics): bool {
            return $metrics['users_count'] === 2 && $metrics['students_count'] === 1 && $metrics['audit_events'] === 0;
        });

        $this->actingAs($manager)->post('/admin/notifications/dispatch', [
            'user_id' => $studentA->id,
            'channel' => 'in_app',
            'title' => 'Branch A Notification',
            'message' => 'Visible only inside branch A.',
        ])->assertRedirect();
        $this->assertDatabaseHas('lms_notifications', [
            'user_id' => $studentA->id,
            'branch_id' => $branchA,
            'title' => 'Branch A Notification',
        ]);
        $this->actingAs($managerB)->post('/admin/notifications/dispatch', [
            'user_id' => $studentA->id,
            'channel' => 'in_app',
            'title' => 'Cross Branch Attempt',
            'message' => 'Must be rejected.',
        ])->assertNotFound();

        $this->actingAs($manager)->post('/admin/dashboard/widgets', ['widgets' => ['plugins']])
            ->assertSessionHasErrors('widgets.0');
    }
}
