<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteBuildersTest extends TestCase
{
    public function test_page_builder_publishes_plain_text_and_a_safe_call_to_action(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/page-builder', [
            'title' => 'Admissions',
            'slug' => 'admissions',
            'template' => 'landing',
            'seo_title' => 'Admissions at AEC',
            'seo_description' => 'Apply to AEC courses.',
            'content_body' => 'Applications are now open.',
            'cta_label' => 'Explore Courses',
            'cta_url' => '/courses',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_pages', [
            'slug' => 'admissions',
            'template' => 'landing',
            'is_published' => true,
        ]);

        $this->get('/page/admissions')
            ->assertOk()
            ->assertSee('Applications are now open.')
            ->assertSee('Explore Courses')
            ->assertSee('href="/courses"', false);
    }

    public function test_page_builder_rejects_script_and_protocol_relative_cta_urls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/page-builder', [
            'title' => 'Unsafe CTA',
            'slug' => 'unsafe-cta',
            'content_body' => 'Content',
            'cta_label' => 'Click',
            'cta_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('cta_url');

        $this->assertSame(0, DB::table('cms_pages')->where('slug', 'unsafe-cta')->count());

        $this->actingAs($admin)->post('/admin/page-builder', [
            'title' => 'External Protocol CTA',
            'slug' => 'protocol-relative',
            'content_body' => 'Content',
            'cta_label' => 'Click',
            'cta_url' => '//evil.example.test/path',
        ])->assertSessionHasErrors('cta_url');

        $this->assertSame(0, DB::table('cms_pages')->where('slug', 'protocol-relative')->count());
    }

    public function test_slider_and_carousel_reject_external_media_paths_and_unsafe_ctas(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['slider-builder', 'carousel-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/slider-builder', [
            'key_name' => 'hero',
            'title' => 'Unsafe Slide',
            'image_path' => 'https://remote.example.test/image.jpg',
            'cta_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors(['image_path', 'cta_url']);

        $this->actingAs($admin)->post('/admin/carousel-builder', [
            'carousel_group' => 'featured_carousel',
            'title' => 'Unsafe Card',
            'image_path' => '//remote.example.test/image.jpg',
            'cta_url' => 'data:text/html,hello',
        ])->assertSessionHasErrors(['image_path', 'cta_url']);

        $this->assertSame(0, DB::table('cms_sliders')->count());
        $this->assertSame(0, DB::table('cms_carousels')->count());
    }
}
