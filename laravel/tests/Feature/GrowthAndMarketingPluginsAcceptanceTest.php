<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CarouselBuilder\Models\CarouselItem;
use Plugins\Modules\GmailMarketing\Models\EmailCampaign;
use Tests\TestCase;

class GrowthAndMarketingPluginsAcceptanceTest extends TestCase
{
    private function enableGrowthPlugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'carousel-builder',
            'gmail-marketing',
            'google-console',
            'meta-pixel',
            'reports-analytics',
            'seo-manager',
        ]);
    }

    public function test_carousel_builder_supports_admin_create_update_and_delete_workflow(): void
    {
        $this->enableGrowthPlugins();
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/carousel-builder', [
            'carousel_group' => 'featured_carousel',
            'title' => 'IELTS Success Programme',
            'subtitle' => 'Join the next batch',
            'cta_url' => '/courses',
            'rating' => 5,
            'sort_order' => 1,
            'image_path' => '/uploads/ielts.webp',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $item = CarouselItem::query()->where('title', 'IELTS Success Programme')->firstOrFail();

        $this->actingAs($admin)->put('/admin/carousel-builder/' . $item->id, [
            'carousel_group' => 'course_showcase',
            'title' => 'Updated IELTS Programme',
            'subtitle' => 'Updated details',
            'cta_url' => '/courses/ielts',
            'rating' => 4,
            'sort_order' => 2,
            'image_path' => '/uploads/ielts-updated.webp',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_carousels', [
            'id' => $item->id,
            'title' => 'Updated IELTS Programme',
            'carousel_group' => 'course_showcase',
        ]);

        $this->actingAs($admin)->delete('/admin/carousel-builder/' . $item->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('cms_carousels', ['id' => $item->id]);
    }

    public function test_carousel_builder_rejects_unsafe_cta_and_image_paths(): void
    {
        $this->enableGrowthPlugins();
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/carousel-builder', [
            'carousel_group' => 'featured_carousel',
            'title' => 'Unsafe Carousel',
            'cta_url' => 'javascript:alert(1)',
            'image_path' => '//evil.example/image.webp',
        ])->assertSessionHasErrors(['cta_url', 'image_path']);

        $this->assertDatabaseMissing('cms_carousels', ['title' => 'Unsafe Carousel']);
    }

    public function test_gmail_marketing_saves_secret_and_records_campaign_without_claiming_delivery(): void
    {
        $this->enableGrowthPlugins();
        $admin = $this->createUserWithRole('admin');
        $plugins = app(PluginManager::class);

        $this->actingAs($admin)->post('/plugins/gmail-marketing/settings', [
            'gmail_address' => 'campaigns@alomsenglish.example',
            'sender_name' => 'AEC LMS',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => '587',
            // Leave app password empty: no external SMTP delivery is attempted in acceptance tests.
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/plugins/gmail-marketing/campaigns', [
            'subject' => 'IELTS Admission Open',
            'audience' => 'custom',
            'custom_emails' => 'one@example.test, invalid-email; two@example.test',
            'body_html' => '<p>Admissions are open.</p>',
        ])->assertSessionHasNoErrors();

        $campaign = EmailCampaign::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $campaign->recipient_count);
        $this->assertSame('queued', $campaign->status);
        $this->assertSame('campaigns@alomsenglish.example', $plugins->getSetting('gmail-marketing', 'gmail_address'));
        $this->assertSame('', (string) $plugins->getSetting('gmail-marketing', 'app_password'));
    }

    public function test_google_console_and_meta_pixel_settings_are_saved_with_private_tokens(): void
    {
        $this->enableGrowthPlugins();
        $admin = $this->createUserWithRole('admin');
        $plugins = app(PluginManager::class);

        $this->actingAs($admin)->post('/plugins/google-console', [
            'gsc_verification_token' => 'gsc-verification-token',
            'ga4_measurement_id' => 'G-ABC1234567',
            'gtm_container_id' => 'GTM-ABC1234',
            'auto_inject' => '1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/plugins/meta-pixel', [
            'pixel_id' => '123456789012345',
            'capi_access_token' => 'private-capi-test-token',
            'test_event_code' => 'TEST123',
            'events' => ['PageView', 'Lead', 'Purchase'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('G-ABC1234567', $plugins->getSetting('google-console', 'ga4_measurement_id'));
        $this->assertSame('GTM-ABC1234', $plugins->getSetting('google-console', 'gtm_container_id'));
        $this->assertSame('123456789012345', $plugins->getSetting('meta-pixel', 'pixel_id'));
        $this->assertSame('PageView,Lead,Purchase', $plugins->getSetting('meta-pixel', 'track_events'));
        $this->assertSame('private-capi-test-token', $plugins->getSetting('meta-pixel', 'capi_access_token'));

        $this->assertDatabaseHas('plugin_settings', [
            'plugin_slug' => 'meta-pixel',
            'key' => 'capi_access_token',
            'is_encrypted' => true,
        ]);
    }

    public function test_reports_analytics_dashboard_and_audit_csv_are_available_to_admin(): void
    {
        $this->enableGrowthPlugins();
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->get('/admin/reports')
            ->assertOk()
            ->assertSee('Reports');

        $this->actingAs($admin)->get('/admin/reports/audit-export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_seo_manager_serves_robots_and_sitemap_and_saves_global_and_route_rules(): void
    {
        $this->enableGrowthPlugins();
        $admin = $this->createUserWithRole('admin');

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin/');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee('<urlset', false);

        $this->actingAs($admin)->post('/plugins/seo/global', [
            'site_title_seo' => 'Aloms English Center | AEC LMS',
            'site_meta_description' => 'Learn English with structured courses and live classes.',
            'canonical_url' => 'https://alomsenglish.example',
            'robots_txt' => "User-agent: *\nAllow: /\nDisallow: /admin/",
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/plugins/seo/rules', [
            'route_path' => 'courses',
            'meta_title' => 'English Courses',
            'meta_description' => 'Browse English courses.',
            'robots_directive' => 'index,follow',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seo_meta_rules', [
            'route_path' => '/courses',
            'meta_title' => 'English Courses',
            'robots_directive' => 'index,follow',
        ]);

        $this->assertSame('Aloms English Center | AEC LMS', app(PluginManager::class)->getSetting('seo-manager', 'site_title_seo'));
        $this->assertSame(1, DB::table('seo_meta_rules')->where('route_path', '/courses')->count());
    }
}
