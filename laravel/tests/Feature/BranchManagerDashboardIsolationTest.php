<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchManagerDashboardIsolationTest extends TestCase
{
    public function test_branch_manager_only_sees_branch_user_count_and_cannot_select_global_widgets(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management']);

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
        $this->createUserWithRole('student', $branchA);
        $this->createUserWithRole('student', $branchB);

        $permissionId = DB::table('permissions')->where('slug', 'core.dashboard.view')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        SystemSetting::query()->updateOrCreate(
            ['key' => 'admin.dashboard.widgets.user.' . $manager->id],
            ['value' => json_encode(['users', 'plugins', 'backups', 'audit_trail', 'system_info']), 'is_encrypted' => false]
        );

        $response = $this->actingAs($manager)->get('/admin')->assertOk();
        $response->assertSee('Registered Accounts')
            ->assertDontSee('Plugin Overview')
            ->assertDontSee('Encrypted Backups')
            ->assertDontSee('Recent Security Audit Trail')
            ->assertDontSee('System Information');

        preg_match('/data-dashboard-widget="users".*?<div class="mt-1 text-2xl font-extrabold">(\d+)<\/div>/s', $response->getContent(), $matches);
        $this->assertSame('2', $matches[1] ?? null, 'Dashboard count must include only accounts from the assigned branch.');

        $this->actingAs($manager)->post('/admin/dashboard/widgets', ['widgets' => ['plugins']])
            ->assertSessionHasErrors('widgets.0');
    }
}
