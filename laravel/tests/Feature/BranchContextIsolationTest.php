<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Plugins\Modules\CourseManagement\Models\Course;
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


    public function test_teacher_portal_uses_current_teacher_profile_branch_not_stale_user_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'teacher-management', 'course-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'PORTAL-TEACH-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Teacher Account Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'PORTAL-TEACH-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Teacher Profile Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = $this->createUserWithRole('teacher', (int) $branchA);
        DB::table('teachers')->insert([
            'user_id' => $teacher->id,
            'branch_id' => $branchB,
            'employee_code' => 'PORTAL-TEACH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courseA = Course::query()->create([
            'branch_id' => $branchA,
            'code' => 'PORTAL-COURSE-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Stale Account Branch Course',
            'slug' => 'stale-account-branch-' . uniqid(),
            'instructor_name' => $teacher->name,
            'price' => 0,
            'status' => 'published',
        ]);
        $courseB = Course::query()->create([
            'branch_id' => $branchB,
            'code' => 'PORTAL-COURSE-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Current Teacher Profile Course',
            'slug' => 'current-teacher-profile-' . uniqid(),
            'instructor_name' => $teacher->name,
            'price' => 0,
            'status' => 'published',
        ]);

        foreach ([[$courseA, 'Stale Account Branch Live Class'], [$courseB, 'Current Profile Branch Live Class']] as [$course, $title]) {
            DB::table('live_classes')->insert([
                'course_id' => $course->id,
                'title' => $title,
                'instructor_name' => $teacher->name,
                'platform' => 'zoom',
                'meeting_url' => 'https://example.test/' . $course->slug,
                'recording_url' => null,
                'starts_at' => now()->addHour(),
                'status' => 'upcoming',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->pluck('id')->all() === [$courseB->id])
            ->assertViewHas('liveClasses', fn ($classes) => $classes->pluck('title')->all() === ['Current Profile Branch Live Class']);
    }

    public function test_mobile_course_catalog_is_limited_to_the_authenticated_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'mobile-app-api', 'course-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'MCA-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Mobile Catalog Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'MCB-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Mobile Catalog Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $token = $manager->createToken('branch-catalog-test')->plainTextToken;

        Course::query()->create(['code' => 'MOBILE-A', 'title' => 'Branch A Published Course', 'slug' => 'branch-a-published-course', 'price' => 0, 'status' => 'published', 'branch_id' => $branchA]);
        Course::query()->create(['code' => 'MOBILE-B', 'title' => 'Branch B Private Course', 'slug' => 'branch-b-private-course', 'price' => 0, 'status' => 'published', 'branch_id' => $branchB]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/courses')
            ->assertOk()
            ->assertSee('Branch A Published Course')
            ->assertDontSee('Branch B Private Course');
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
