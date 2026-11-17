<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Tests\TestCase;

class BranchManagerCampaignFailClosedTest extends TestCase
{
    private function activateCampaignPlugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'course-management',
            'exam-management',
            'flash-sale',
            'learn-and-earn',
        ]);
    }

    private function grantCampaignPermissions($manager): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['flash_sale.manage', 'learn_earn.manage'])
            ->pluck('id');
        $this->assertCount(2, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);
    }

    public function test_unassigned_branch_manager_cannot_manage_flash_sales_or_learn_and_earn(): void
    {
        $this->activateCampaignPlugins();
        $manager = $this->createUserWithRole('branch_manager');
        $this->grantCampaignPermissions($manager);

        $this->actingAs($manager)->get('/admin/flash-sales')->assertForbidden();
        $this->actingAs($manager)->post('/admin/flash-sales', [])->assertForbidden();
        $this->actingAs($manager)->get('/admin/learn-and-earn')->assertForbidden();
        $this->actingAs($manager)->post('/admin/learn-and-earn/exams', [])->assertForbidden();
        $this->actingAs($manager)->post('/admin/learn-and-earn/categories', [])->assertForbidden();
        $this->actingAs($manager)->post('/admin/learn-and-earn/questions', [])->assertForbidden();
    }

    public function test_inactive_branch_manager_cannot_manage_flash_sales_or_learn_and_earn(): void
    {
        $this->activateCampaignPlugins();
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'INACTIVE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Inactive Campaign Branch',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $this->grantCampaignPermissions($manager);

        $this->actingAs($manager)->get('/admin/flash-sales')->assertForbidden();
        $this->actingAs($manager)->get('/admin/learn-and-earn')->assertForbidden();
    }

    public function test_branch_manager_cannot_view_or_mutate_orphaned_flash_sales_when_courses_are_disabled(): void
    {
        $this->activateCampaignPlugins();
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'ORPHAN-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Orphan Campaign Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $this->grantCampaignPermissions($manager);
        $course = Course::query()->create([
            'code' => 'ORPHAN-SALE-01',
            'title' => 'Orphaned Flash Sale Course',
            'slug' => 'orphaned-flash-sale-course',
            'price' => 100,
            'status' => 'published',
            'branch_id' => $branchId,
        ]);
        $saleId = DB::table('flash_sales')->insertGetId([
            'course_id' => $course->id,
            'title' => 'Orphaned Campaign',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Core\Plugins\PluginManager::class)->disable('course-management');

        $this->actingAs($manager)->get('/admin/flash-sales')
            ->assertOk()
            ->assertDontSee('Orphaned Campaign');
        $this->actingAs($manager)->post('/admin/flash-sales/' . $saleId . '/toggle')
            ->assertStatus(503);
    }

}
