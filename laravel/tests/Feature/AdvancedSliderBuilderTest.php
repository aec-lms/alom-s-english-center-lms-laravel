<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\SliderBuilder\Models\HeroSlider;
use Tests\TestCase;

class AdvancedSliderBuilderTest extends TestCase
{
    public function test_admin_can_create_a_responsive_scheduled_slider(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)->post('/admin/slider-builder', [
            'key_name' => 'hero',
            'title' => 'Advanced hero slide',
            'subtitle' => 'Responsive campaign content',
            'image_path' => '/assets/images/hero.webp',
            'mobile_image_path' => '/assets/images/hero-mobile.webp',
            'button_label' => 'Explore now',
            'cta_url' => '/courses',
            'sort_order' => 3,
            'text_alignment' => 'left',
            'transition' => 'fade',
            'overlay_opacity' => 55,
            'duration_ms' => 6500,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'is_active' => '1',
            'open_new_tab' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertDatabaseHas('cms_sliders', [
            'title' => 'Advanced hero slide',
            'mobile_image_path' => '/assets/images/hero-mobile.webp',
            'button_label' => 'Explore now',
            'text_alignment' => 'left',
            'transition' => 'fade',
            'overlay_opacity' => 55,
            'duration_ms' => 6500,
            'open_new_tab' => true,
        ]);
    }

    public function test_slider_update_rejects_unsafe_cta_and_image_paths(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        $admin = $this->createUserWithRole('admin');
        $slide = HeroSlider::query()->create([
            'key_name' => 'hero',
            'title' => 'Keep this title',
            'cta_url' => '/courses',
            'image_path' => '/assets/images/hero.webp',
            'text_alignment' => 'center',
            'transition' => 'fade',
            'overlay_opacity' => 45,
            'duration_ms' => 5000,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/admin/slider-builder/' . $slide->id, [
            'key_name' => 'hero',
            'title' => 'Tampered title',
            'cta_url' => 'javascript:alert(1)',
            'image_path' => '//evil.example/image.jpg',
            'text_alignment' => 'center',
            'transition' => 'fade',
            'overlay_opacity' => 45,
            'duration_ms' => 5000,
        ])->assertSessionHasErrors(['cta_url', 'image_path']);

        $this->assertDatabaseHas('cms_sliders', [
            'id' => $slide->id,
            'title' => 'Keep this title',
            'cta_url' => '/courses',
        ]);
    }

    public function test_admin_can_reorder_slides_without_mixing_placements(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        $admin = $this->createUserWithRole('admin');
        $first = HeroSlider::query()->create(['key_name' => 'hero', 'title' => 'First']);
        $second = HeroSlider::query()->create(['key_name' => 'hero', 'title' => 'Second']);
        $banner = HeroSlider::query()->create(['key_name' => 'banner_slider', 'title' => 'Banner']);

        $this->actingAs($admin)->patchJson('/admin/slider-builder/reorder', [
            'key_name' => 'hero',
            'items' => [['id' => $second->id], ['id' => $first->id]],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(10, $second->fresh()->sort_order);
        $this->assertSame(20, $first->fresh()->sort_order);
        $this->assertSame(0, $banner->fresh()->sort_order);
    }

    public function test_visible_now_scope_excludes_inactive_and_out_of_window_slides(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);

        $visible = HeroSlider::query()->create([
            'key_name' => 'hero', 'title' => 'Visible now', 'is_active' => true,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinute(),
        ]);
        HeroSlider::query()->create([
            'key_name' => 'hero', 'title' => 'Not started', 'is_active' => true,
            'starts_at' => now()->addHour(),
        ]);
        HeroSlider::query()->create([
            'key_name' => 'hero', 'title' => 'Expired', 'is_active' => true,
            'ends_at' => now()->subMinute(),
        ]);
        HeroSlider::query()->create([
            'key_name' => 'hero', 'title' => 'Paused', 'is_active' => false,
        ]);

        $this->assertSame([$visible->id], HeroSlider::query()->visibleNow()->pluck('id')->all());
    }

    public function test_recycled_slides_are_never_rendered_on_the_public_homepage(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);

        $slide = HeroSlider::query()->create([
            'key_name' => 'hero',
            'title' => 'Private Recycled Slider Campaign',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $slide->delete();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Private Recycled Slider Campaign');
    }

}
