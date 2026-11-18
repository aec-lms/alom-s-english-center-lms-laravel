<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\PageBuilder\Models\BuilderPage;
use Plugins\Modules\PageBuilder\Models\BuilderPageRevision;
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
    public function test_page_builder_announces_block_reordering_for_assistive_technology(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->get('/admin/page-builder')
            ->assertOk()
            ->assertSee('data-block-status', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('aria-atomic="true"', false)
            ->assertSee('aria-label="Move block up"', false)
            ->assertSee('aria-label="Move block down"', false);
    }

    private function createRevisionTestPage($admin, string $slug, string $title, string $content): BuilderPage
    {
        $this->actingAs($admin)->post('/admin/page-builder', [
            'title' => $title,
            'slug' => $slug,
            'template' => 'standard',
            'blocks' => [['type' => 'heading', 'content' => $content, 'align' => 'left']],
            'is_published' => '1',
        ])->assertRedirect();

        return BuilderPage::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_page_revisions_restore_old_content_and_preserve_a_new_revision(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');
        $page = $this->createRevisionTestPage($admin, 'revision-history-page', 'Original title', 'Original heading');
        $firstRevision = $page->revisions()->where('version', 1)->firstOrFail();

        $this->actingAs($admin)->put('/admin/page-builder/' . $page->id, [
            'title' => 'Updated title',
            'slug' => 'revision-history-page',
            'template' => 'landing',
            'blocks' => [['type' => 'heading', 'content' => 'Updated heading', 'align' => 'center']],
            'is_published' => '1',
        ])->assertRedirect();

        $this->assertDatabaseCount('cms_page_revisions', 2);
        $this->actingAs($admin)->post(route('plugins.page_builder.revisions.restore', [$page, $firstRevision]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('Original title', $page->title);
        $this->assertSame('revision-history-page', $page->slug);
        $this->assertSame('standard', $page->template);
        $this->assertSame('Original heading', $page->blocks_json[0]['content']);
        $this->assertSame(3, $page->revisions()->max('version'));
        $this->assertDatabaseHas('cms_page_revisions', [
            'builder_page_id' => $page->id,
            'version' => 3,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/admin/page-builder')
            ->assertOk()
            ->assertSee('Revision history')
            ->assertSee('Version 3')
            ->assertSee('Restore version 1');
    }

    public function test_page_revision_restore_rejects_a_revision_from_another_page(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');
        $pageA = $this->createRevisionTestPage($admin, 'revision-page-a', 'Page A', 'A content');
        $pageB = $this->createRevisionTestPage($admin, 'revision-page-b', 'Page B', 'B content');
        $foreignRevision = $pageB->revisions()->firstOrFail();

        $this->actingAs($admin)->post(route('plugins.page_builder.revisions.restore', [$pageA, $foreignRevision]))
            ->assertNotFound();

        $this->assertSame('Page A', $pageA->fresh()->title);
        $this->assertSame('A content', $pageA->fresh()->blocks_json[0]['content']);
    }

    public function test_page_revision_restore_rejects_slug_collisions_and_malformed_snapshots(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');
        $page = $this->createRevisionTestPage($admin, 'old-revision-slug', 'Original', 'Original content');
        $oldRevision = $page->revisions()->where('version', 1)->firstOrFail();

        $this->actingAs($admin)->put('/admin/page-builder/' . $page->id, [
            'title' => 'Renamed page',
            'slug' => 'new-revision-slug',
            'template' => 'standard',
            'blocks' => [['type' => 'text', 'content' => 'New content']],
            'is_published' => '1',
        ])->assertRedirect();

        $this->createRevisionTestPage($admin, 'old-revision-slug', 'Slug owner', 'Other page');
        $this->actingAs($admin)->post(route('plugins.page_builder.revisions.restore', [$page, $oldRevision]))
            ->assertSessionHasErrors('revision');
        $this->assertSame('new-revision-slug', $page->fresh()->slug);

        $malformed = $page->revisions()->where('version', 1)->firstOrFail();
        $malformed->update(['snapshot' => [
            'slug' => 'invalid slug with spaces',
            'title' => 'Bad snapshot',
            'template' => 'standard',
            'banner_image' => null,
            'blocks_json' => [['type' => 'heading', 'content' => 'Bad']],
            'seo_title' => 'Bad snapshot',
            'seo_description' => null,
            'is_published' => true,
        ]]);

        $this->actingAs($admin)->post(route('plugins.page_builder.revisions.restore', [$page, $malformed]))
            ->assertSessionHasErrors('revision');
        $this->assertSame('new-revision-slug', $page->fresh()->slug);
        $this->assertSame('Renamed page', $page->fresh()->title);
    }

}
