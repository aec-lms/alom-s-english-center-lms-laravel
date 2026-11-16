<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Updates\UniversalUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class MobileAppUpdateAndPublicParityTest extends TestCase
{
    public function test_mobile_app_api_and_admin_configuration_work_for_ios_and_android(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'course-management',
            'exam-management',
            'notice-board',
            'branch-management',
            'student-management',
            'certificate-management',
            'cms-website',
            'mobile-app-api',
        ]);

        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)
            ->post('/admin/mobile-app', [
                'ios_enabled' => '1',
                'ios_bundle_id' => 'com.alomsenglishcenter.ios',
                'ios_store_url' => 'https://apps.apple.com/app/aloms-english-center',
                'ios_min_version' => '2.0.0',
                'ios_latest_version' => '2.2.0',
                'android_enabled' => '1',
                'android_package_name' => 'com.alomsenglishcenter.app',
                'android_store_url' => 'https://play.google.com/store/apps/details?id=com.alomsenglishcenter.app',
                'android_min_version' => '2.0.0',
                'android_latest_version' => '2.2.0',
                'deep_link_scheme' => 'alomsenglishcenter://',
                'push_enabled' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->getJson('/api/mobile/config')
            ->assertOk()
            ->assertJsonPath('ios.bundle_id', 'com.alomsenglishcenter.ios')
            ->assertJsonPath('android.package_name', 'com.alomsenglishcenter.app');

        $registerResp = $this->postJson('/api/mobile/register', [
            'name' => 'Mobile Learner',
            'email' => 'mobile.learner@alomsenglishcenter.com',
            'password' => 'StrongPassword!2026',
            'platform' => 'ios',
            'device_name' => 'iPhone 16 Pro',
            'app_version' => '2.2.0',
            'push_token' => 'apns-token-xyz-001',
        ]);

        $registerResp->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $token = $registerResp->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/mobile/push-token', [
                'platform' => 'android',
                'device_name' => 'Pixel 9',
                'app_version' => '2.2.0',
                'push_token' => 'fcm-token-abc-999',
            ])
            ->assertOk();

        $this->assertTrue(Schema::hasTable('mobile_device_tokens'));
        $this->assertSame(2, DB::table('mobile_device_tokens')->count());

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'email'], 'enrollments']);

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonStructure(['courses', 'ebooks', 'products', 'notices']);
    }

    public function test_mobile_notices_only_expose_global_and_authenticated_users_own_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'notice-board',
            'mobile-app-api',
        ]);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'DHK-A',
            'name' => 'Dhaka Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'DHK-B',
            'name' => 'Dhaka Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['branch_id' => null, 'title' => 'Global Notice'],
            ['branch_id' => $branchA, 'title' => 'Branch A Notice'],
            ['branch_id' => $branchB, 'title' => 'Branch B Private Notice'],
        ] as $notice) {
            DB::table('notices')->insert($notice + [
                'body' => 'Notice content',
                'audience_role' => 'all',
                'is_pinned' => false,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/mobile/notices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['title' => 'Global Notice'])
            ->assertJsonMissing(['title' => 'Branch A Notice'])
            ->assertJsonMissing(['title' => 'Branch B Private Notice']);

        $studentA = $this->createUserWithRole('student', (int) $branchA);
        $this->actingAs($studentA)
            ->getJson('/api/mobile/notices')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['title' => 'Global Notice'])
            ->assertJsonFragment(['title' => 'Branch A Notice'])
            ->assertJsonMissing(['title' => 'Branch B Private Notice']);

        $this->actingAs($studentA)
            ->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonCount(2, 'notices')
            ->assertJsonMissing(['title' => 'Branch B Private Notice']);
    }

    public function test_mobile_notice_endpoints_enforce_audience_and_publication_time(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['notice-board', 'mobile-app-api']);

        foreach ([
            ['title' => 'Everyone Notice', 'audience_role' => 'all', 'published_at' => now()],
            ['title' => 'Student Only Notice', 'audience_role' => 'student', 'published_at' => now()],
            ['title' => 'Teacher Only Notice', 'audience_role' => 'teacher', 'published_at' => now()],
            ['title' => 'Future Student Notice', 'audience_role' => 'student', 'published_at' => now()->addDay()],
        ] as $notice) {
            DB::table('notices')->insert($notice + [
                'branch_id' => null,
                'body' => 'Audience restricted content',
                'is_pinned' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/mobile/notices')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Everyone Notice'])
            ->assertJsonMissing(['title' => 'Student Only Notice'])
            ->assertJsonMissing(['title' => 'Teacher Only Notice'])
            ->assertJsonMissing(['title' => 'Future Student Notice']);

        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->getJson('/api/mobile/notices')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Everyone Notice'])
            ->assertJsonFragment(['title' => 'Student Only Notice'])
            ->assertJsonMissing(['title' => 'Teacher Only Notice'])
            ->assertJsonMissing(['title' => 'Future Student Notice']);

        $this->actingAs($student)->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Student Only Notice'])
            ->assertJsonMissing(['title' => 'Teacher Only Notice'])
            ->assertJsonMissing(['title' => 'Future Student Notice']);

        $teacher = $this->createUserWithRole('teacher');
        $this->actingAs($teacher)->getJson('/api/mobile/notices')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Everyone Notice'])
            ->assertJsonFragment(['title' => 'Teacher Only Notice'])
            ->assertJsonMissing(['title' => 'Student Only Notice']);
    }

    public function test_mobile_course_detail_returns_published_course_without_undefined_relations(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'mobile-app-api']);

        $course = \Plugins\Modules\CourseManagement\Models\Course::query()->create([
            'code' => 'MOBILE-COURSE-01',
            'title' => 'Mobile API Course Detail',
            'slug' => 'mobile-api-course-detail',
            'description' => 'Published course description',
            'price' => 2500,
            'status' => 'published',
        ]);

        $this->getJson('/api/mobile/courses/' . $course->slug)
            ->assertOk()
            ->assertJsonPath('data.id', $course->id)
            ->assertJsonPath('data.title', 'Mobile API Course Detail')
            ->assertJsonPath('data.description', 'Published course description');

        $course->update(['status' => 'draft']);
        $this->getJson('/api/mobile/courses/' . $course->slug)
            ->assertNotFound();
    }

    public function test_universal_update_service_blocks_zip_path_traversal_entries(): void
    {
        $this->markSystemInstalled();

        $zipPath = sys_get_temp_dir() . '/aec_traversal_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('update-manifest.json', json_encode([
            'target_type' => 'core',
            'target_slug' => 'aec-lms-core',
            'version' => '2.1.0',
        ]));
        $zip->addFromString('../../etc/evil.txt', 'malicious');
        $zip->close();

        $sha = hash_file('sha256', $zipPath);
        $hmac = hash_hmac('sha256', $sha, (string) config('lms.plugin_signing_key'));

        $updates = app(UniversalUpdateService::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe ZIP path detected');
            $updates->applyVerifiedUpdate($zipPath, $sha, $hmac, null);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_public_parity_routes_contact_inquiry_coupon_and_sitemap_work(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'course-management',
            'marketing-management',
            'seo-manager',
            'cms-website',
        ]);

        $this->get('/about-us')->assertOk()->assertSee("Alom's English Center");
        $this->get('/terms-and-conditions')->assertOk();
        $this->get('/privacy-policy')->assertOk();
        $this->get('/refund-policy')->assertOk();
        $this->get('/contact-us')->assertOk();

        $this->post('/contact-us', [
            'name' => 'Tasnim Akter',
            'email' => 'tasnim@example.com',
            'phone' => '+8801711223344',
            'subject' => 'IELTS Evening Batch',
            'message' => 'Please share class timing and tuition fee details.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_inquiries', [
            'email' => 'tasnim@example.com',
            'subject' => 'IELTS Evening Batch',
        ]);

        DB::table('promo_coupons')->insert([
            'code' => 'AEC25',
            'discount_percent' => 25,
            'max_uses' => 100,
            'used_count' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/courses/coupon-check', [
            'coupon_code' => 'AEC25',
            'price' => 10000,
        ])->assertOk()
            ->assertJson([
                'valid' => true,
                'discount_percent' => 25,
                'final_price' => 7500,
            ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('/about-us')
            ->assertSee('/contact-us')
            ->assertSee('/board-result');
    }
}
