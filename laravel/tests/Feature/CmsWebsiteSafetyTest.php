<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CmsWebsiteSafetyTest extends TestCase
{
    public function test_homepage_defaults_do_not_publish_unverified_contact_or_store_details(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');

        $controller = app(\Plugins\Modules\CmsWebsite\Http\Controllers\CmsController::class);
        $view = $controller->index();
        $homepage = $view->getData()['homepage'];

        $this->assertSame('', $homepage['footer_license_no']);
        $this->assertSame('', $homepage['footer_address']);
        $this->assertSame('', $homepage['footer_phone']);
        $this->assertSame('', $homepage['footer_email']);
        $this->assertSame('', $homepage['app_ios_url']);
        $this->assertSame('', $homepage['app_android_url']);
        $this->assertFalse($homepage['show_app_banner']);
        $this->assertFalse($homepage['footer_payment_strip']);
    }

    public function test_cms_post_rejects_unknown_category_ids(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/cms/posts', [
            'title' => 'Invalid Category Post',
            'content' => 'Safe sample content',
            'category_id' => 999999,
        ])->assertSessionHasErrors('category_id');
    }
}
