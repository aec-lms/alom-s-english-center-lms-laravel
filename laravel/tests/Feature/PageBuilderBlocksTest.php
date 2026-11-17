<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageBuilderBlocksTest extends TestCase
{
    public function test_admin_can_build_and_publish_a_page_with_structured_blocks(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/page-builder', [
            'title' => 'Our English Programs',
            'slug' => 'our-english-programs',
            'template' => 'landing',
            'seo_title' => 'English Programs',
            'seo_description' => 'Explore our English programs.',
            'blocks' => [
                ['type' => 'heading', 'content' => 'Learn English with confidence', 'align' => 'center'],
                ['type' => 'text', 'content' => 'Small classes and practical lessons.', 'align' => 'left'],
                ['type' => 'image', 'url' => '/uploads/pages/classroom.webp', 'alt' => 'Students in class'],
                ['type' => 'button', 'content' => 'Explore courses', 'url' => '/courses', 'align' => 'center'],
                ['type' => 'spacer', 'height' => 64],
                ['type' => 'course_grid'],
                ['type' => 'instructor_grid'],
            ],
            'is_published' => '1',
        ])->assertRedirect();

        $page = DB::table('cms_pages')->where('slug', 'our-english-programs')->first();
        $this->assertNotNull($page);

        $blocks = json_decode($page->blocks_json, true);
        $this->assertSame(['heading', 'text', 'image', 'button', 'spacer', 'course_grid', 'instructor_grid'], array_column($blocks, 'type'));
        $this->assertSame('/courses', $blocks[3]['url']);

        $this->get('/page/our-english-programs')
            ->assertOk()
            ->assertSee('Learn English with confidence')
            ->assertSee('Small classes and practical lessons.')
            ->assertSee('/uploads/pages/classroom.webp')
            ->assertSee('Explore courses')
            ->assertSee('Explore our courses')
            ->assertSee('Published courses will appear here automatically.')
            ->assertSee('Meet our instructors')
            ->assertSee('Active instructors will appear here automatically.');
    }

    public function test_page_builder_rejects_unsafe_block_links(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/page-builder', [
            'title' => 'Unsafe page',
            'slug' => 'unsafe-page',
            'blocks' => [
                ['type' => 'button', 'content' => 'Click', 'url' => 'javascript:alert(1)'],
            ],
        ])->assertSessionHasErrors('blocks.0.url');

        $this->assertDatabaseMissing('cms_pages', ['slug' => 'unsafe-page']);
    }

    public function test_public_page_does_not_render_unsafe_legacy_block_urls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);

        DB::table('cms_pages')->insert([
            'slug' => 'legacy-unsafe-page',
            'title' => 'Legacy page',
            'template' => 'standard',
            'blocks_json' => json_encode([
                ['type' => 'cta', 'label' => 'Unsafe link', 'url' => 'javascript:alert(1)'],
                ['type' => 'image', 'url' => 'data:text/html,bad', 'alt' => 'Bad image'],
                ['type' => 'rich_text', 'content' => 'Safe legacy content'],
            ], JSON_THROW_ON_ERROR),
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/page/legacy-unsafe-page')
            ->assertOk()
            ->assertSee('Safe legacy content')
            ->assertDontSee('javascript:alert(1)')
            ->assertDontSee('data:text/html,bad');
    }
}
