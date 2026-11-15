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
            'certificate-management',
            'cms-website',
            'mobile-app-api',
        ]);

        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)
            ->post('/admin/mobile-app', [
                'ios_bundle_id' => 'com.alomsenglishcenter.ios',
                'ios_app_store_url' => 'https://apps.apple.com/app/aloms-english-center',
                'ios_min_version' => '2.0.0',
                'ios_latest_version' => '2.2.0',
                'android_package_name' => 'com.alomsenglishcenter.app',
                'android_play_store_url' => 'https://play.google.com/store/apps/details?id=com.alomsenglishcenter.app',
                'android_min_version' => '2.0.0',
                'android_latest_version' => '2.2.0',
                'deep_link_scheme' => 'alomsenglishcenter://',
                'push_notifications_enabled' => '1',
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

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonStructure(['courses', 'ebooks', 'products', 'notices']);
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
        $hmac = hash_hmac('sha256', $sha . '|core|aec-lms-core|2.1.0', (string) config('lms.plugin_signing_key'));

        $updates = app(UniversalUpdateService::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe path traversal');
            $updates->applySignedUpdate($zipPath, $sha, $hmac, null);
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
