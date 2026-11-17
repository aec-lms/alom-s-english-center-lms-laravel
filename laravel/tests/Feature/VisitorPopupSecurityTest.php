<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\VisitorPopupAds\Models\VisitorPopup;
use Tests\TestCase;

class VisitorPopupSecurityTest extends TestCase
{
    public function test_admin_can_edit_all_popup_content_and_control_frequency(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['visitor-popup-ads']);
        $admin = $this->createUserWithRole('admin');

        $popup = VisitorPopup::query()->create([
            'title' => 'Original Offer',
            'badge_text' => 'OLD',
            'description' => 'Old description',
            'image_path' => '/uploads/popups/old.jpg',
            'cta_text' => 'Old CTA',
            'cta_url' => '/courses',
            'delay_seconds' => 3,
            'frequency' => 'once_per_session',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/plugins/visitor-popups/' . $popup->id, [
            'title' => 'Updated IELTS Offer',
            'badge_text' => 'NEW OFFER',
            'description' => 'Updated description and promotion details',
            'cta_text' => 'Join Today',
            'cta_url' => '/ielts-admission',
            'image_url' => 'https://example.test/promo.webp',
            'delay_seconds' => 8,
            'frequency' => 'once_per_day',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visitor_popups', [
            'id' => $popup->id,
            'title' => 'Updated IELTS Offer',
            'badge_text' => 'NEW OFFER',
            'description' => 'Updated description and promotion details',
            'image_path' => 'https://example.test/promo.webp',
            'cta_text' => 'Join Today',
            'cta_url' => '/ielts-admission',
            'delay_seconds' => 8,
            'frequency' => 'once_per_day',
            'is_active' => true,
        ]);
    }

    public function test_popup_rejects_unsafe_cta_and_image_url_schemes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['visitor-popup-ads']);
        $admin = $this->createUserWithRole('admin');
        $popup = VisitorPopup::query()->create([
            'title' => 'Safe Offer',
            'badge_text' => 'OFFER',
            'description' => 'Description',
            'cta_text' => 'Join',
            'cta_url' => '/courses',
            'delay_seconds' => 3,
            'frequency' => 'always',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/plugins/visitor-popups/' . $popup->id, [
            'title' => 'Unsafe CTA',
            'badge_text' => 'OFFER',
            'description' => 'Description',
            'cta_text' => 'Join',
            'cta_url' => 'javascript:alert(1)',
            'image_url' => 'https://example.test/banner.webp',
            'delay_seconds' => 3,
            'frequency' => 'always',
            'is_active' => '1',
        ])->assertSessionHasErrors('cta_url');

        $this->actingAs($admin)->put('/plugins/visitor-popups/' . $popup->id, [
            'title' => 'Unsafe Image',
            'badge_text' => 'OFFER',
            'description' => 'Description',
            'cta_text' => 'Join',
            'cta_url' => '/courses',
            'image_url' => 'javascript:alert(1)',
            'delay_seconds' => 3,
            'frequency' => 'always',
            'is_active' => '1',
        ])->assertSessionHasErrors('image_url');
    }
}
