<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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

    public function test_initial_hero_slide_honors_open_new_tab_before_javascript_initializes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        HeroSlider::query()->create([
            'key_name' => 'hero',
            'title' => 'External campaign',
            'image_path' => '/assets/images/external-campaign.webp',
            'cta_url' => 'https://example.com/campaign',
            'open_new_tab' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('target="_blank" rel="noopener noreferrer"', false);
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

    public function test_slider_updates_validate_schedule_against_omitted_existing_boundaries(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        $admin = $this->createUserWithRole('admin');
        $start = now()->addDay()->startOfMinute();
        $end = now()->addDays(3)->startOfMinute();
        $slide = HeroSlider::query()->create([
            'key_name' => 'hero',
            'title' => 'Scheduled Slider',
            'starts_at' => $start,
            'ends_at' => $end,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/admin/slider-builder/' . $slide->id, [
            'key_name' => 'hero',
            'title' => 'Scheduled Slider',
            'starts_at' => now()->addDays(4)->toDateTimeString(),
        ])->assertSessionHasErrors('ends_at');

        $this->actingAs($admin)->put('/admin/slider-builder/' . $slide->id, [
            'key_name' => 'hero',
            'title' => 'Scheduled Slider',
            'ends_at' => now()->addHours(12)->toDateTimeString(),
        ])->assertSessionHasErrors('ends_at');

        $this->actingAs($admin)->put('/admin/slider-builder/' . $slide->id, [
            'key_name' => 'hero',
            'title' => 'Scheduled Slider',
            'starts_at' => $end->toDateTimeString(),
        ])->assertSessionHasErrors('ends_at');

        $this->assertTrue($slide->fresh()->starts_at->equalTo($start));
        $this->assertTrue($slide->fresh()->ends_at->equalTo($end));
    }

    public function test_zero_overlay_opacity_is_preserved_on_initial_render_and_slider_changes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        HeroSlider::query()->create([
            'key_name' => 'hero',
            'title' => 'Zero overlay opacity',
            'image_path' => '/assets/images/hero.webp',
            'overlay_opacity' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('style="opacity: 0"', false)
            ->assertSee("opacityValue === '' ? 45 : opacityValue", false)
            ->assertSee('))) / 90;', false);
    }


    public function test_media_cleanup_never_follows_a_symlinked_slider_directory(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlink support is unavailable on this platform.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder']);
        $admin = $this->createUserWithRole('admin');

        $uploads = public_path('uploads');
        File::ensureDirectoryExists($uploads);
        $sliderDirectory = public_path('uploads/sliders');
        $backupDirectory = public_path('uploads/sliders-backup-' . bin2hex(random_bytes(4)));
        $externalDirectory = sys_get_temp_dir() . '/aec-slider-target-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($externalDirectory);
        file_put_contents($externalDirectory . '/keep.jpg', 'must-not-delete');
        $hadOriginalDirectory = file_exists($sliderDirectory) || is_link($sliderDirectory);

        try {
            if ($hadOriginalDirectory) {
                rename($sliderDirectory, $backupDirectory);
            }
            if (!@symlink($externalDirectory, $sliderDirectory)) {
                $this->markTestSkipped('The test runner does not permit creating symlinks.');
            }

            $this->actingAs($admin)->post('/admin/slider-builder', [
                'key_name' => 'hero',
                'title' => 'Blocked symlink upload',
                'image' => UploadedFile::fake()->image('outside.jpg'),
            ])->assertSessionHasErrors('image');

            $this->assertSame(['keep.jpg'], array_values(array_diff(scandir($externalDirectory) ?: [], ['.', '..'])));

            $slide = HeroSlider::query()->create([
                'key_name' => 'hero',
                'title' => 'Symlink cleanup guard',
                'image_path' => '/uploads/sliders/keep.jpg',
                'is_active' => true,
            ]);

            $this->actingAs($admin)->put('/admin/slider-builder/' . $slide->id, [
                'key_name' => 'hero',
                'title' => 'Symlink cleanup guard',
                'image_path' => '/assets/replacement.jpg',
            ])->assertRedirect();

            $this->assertFileExists($externalDirectory . '/keep.jpg');
            $this->assertSame('must-not-delete', file_get_contents($externalDirectory . '/keep.jpg'));
        } finally {
            if (is_link($sliderDirectory)) {
                unlink($sliderDirectory);
            }
            if ($hadOriginalDirectory && file_exists($backupDirectory)) {
                rename($backupDirectory, $sliderDirectory);
            }
            File::deleteDirectory($externalDirectory);
        }
    }

}
