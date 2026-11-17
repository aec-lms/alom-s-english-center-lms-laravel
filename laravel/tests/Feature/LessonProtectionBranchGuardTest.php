<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LessonProtectionBranchGuardTest extends TestCase
{
    public function test_branch_manager_is_denied_when_branch_plugin_is_disabled_but_branch_table_remains(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'MEDIA-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Lesson Media Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);
        $permissionId = DB::table('permissions')->where('slug', 'courses.manage')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        app(PluginManager::class)->disable('branch-management');

        $this->assertTrue(Schema::hasTable('branches'));
        $this->assertFalse(app(PluginManager::class)->isEnabled('branch-management'));
        $this->actingAs($manager)->get('/admin/courses/content-protection')->assertForbidden();
    }
}
