<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CmsWebsiteSafetyTest extends TestCase
{
    public function test_cms_post_rejects_unknown_categories_and_unsafe_media_urls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/cms/posts', [
            'title' => 'Unsafe CMS Article',
            'content' => 'Plain text article content',
            'category_id' => 999999,
            'thumbnail_url' => 'javascript:alert(1)',
            'pdf_url' => 'data:text/html,unsafe',
            'pdf_thumbnail_url' => 'https://example.test/preview.webp',
            'is_published' => '1',
        ])->assertSessionHasErrors(['category_id', 'thumbnail_url', 'pdf_url']);

        $this->assertDatabaseCount('cms_posts', 0);
    }

    public function test_cms_post_accepts_safe_relative_and_https_media_urls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/cms/posts', [
            'title' => 'Safe CMS Article',
            'content' => 'Plain text article content',
            'thumbnail_url' => '/uploads/posts/article.webp',
            'pdf_url' => 'https://cdn.example.test/guide.pdf',
            'pdf_thumbnail_url' => '/uploads/posts/pdf-thumbs/guide.webp',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $post = DB::table('cms_posts')->where('title', 'Safe CMS Article')->first();
        $this->assertNotNull($post);
        $this->assertSame('/uploads/posts/article.webp', $post->thumbnail_path);
        $this->assertSame('https://cdn.example.test/guide.pdf', $post->pdf_path);
        $this->assertSame('/uploads/posts/pdf-thumbs/guide.webp', $post->pdf_thumbnail);
    }

    public function test_cms_post_update_rejects_a_nonexistent_category(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create([
            'slug' => 'existing-cms-post',
            'title' => 'Existing CMS Post',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Existing content',
            'is_published' => true,
        ]);

        $this->actingAs($admin)->put('/admin/cms/posts/' . $post->id, [
            'title' => 'Updated CMS Post',
            'slug' => 'updated-cms-post',
            'category_id' => 999999,
            'content' => 'Updated content',
            'is_published' => '1',
        ])->assertSessionHasErrors('category_id');

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'title' => 'Existing CMS Post',
            'slug' => 'existing-cms-post',
        ]);
    }


    public function test_soft_deleted_post_can_be_restored_with_its_media_references_intact(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create([
            'slug' => 'restore-cms-post',
            'title' => 'Restore CMS Post',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Content to restore',
            'thumbnail_path' => '/uploads/posts/restore.webp',
            'pdf_path' => 'https://cdn.example.test/restore.pdf',
            'is_published' => true,
        ]);

        $this->actingAs($admin)->delete('/admin/cms/posts/' . $post->id)
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('cms_posts', ['id' => $post->id]);
        $this->assertSame('/uploads/posts/restore.webp', CmsPost::withTrashed()->findOrFail($post->id)->thumbnail_path);

        $this->actingAs($admin)->post('/admin/cms/posts/' . $post->id . '/restore')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'deleted_at' => null,
            'thumbnail_path' => '/uploads/posts/restore.webp',
        ]);
    }

    public function test_trashed_post_can_be_permanently_deleted(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create([
            'slug' => 'permanently-delete-cms-post',
            'title' => 'Permanently Delete CMS Post',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Content to delete',
            'is_published' => false,
        ]);
        $post->delete();

        $this->actingAs($admin)->delete('/admin/cms/posts/' . $post->id . '/force')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('cms_posts', ['id' => $post->id]);
    }


    public function test_post_update_saves_revision_and_revision_can_be_restored(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create(['slug' => 'revision-test-post', 'title' => 'Original Title', 'category' => 'General', 'author' => 'AEC LMS', 'content' => 'Original content', 'is_published' => false]);
        $this->actingAs($admin)->put('/admin/cms/posts/' . $post->id, [
            'title' => 'Changed Title', 'slug' => 'changed-title', 'content' => 'Changed content', 'is_published' => '1',
        ])->assertSessionHasNoErrors();
        $revision = DB::table('cms_post_revisions')->where('post_id', $post->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame('Original Title', $revision->title);
        $this->actingAs($admin)->post('/admin/cms/posts/' . $post->id . '/revisions/' . $revision->id . '/restore')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'title' => 'Original Title', 'slug' => 'revision-test-post', 'content' => 'Original content']);
        $this->assertSame(2, DB::table('cms_post_revisions')->where('post_id', $post->id)->count());
        $this->assertDatabaseHas('cms_post_revisions', ['post_id' => $post->id, 'title' => 'Changed Title', 'content' => 'Changed content']);
    }



    public function test_trashed_post_keeps_its_media_and_is_hidden_from_public_hub_until_restored(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');

        $relativePath = '/uploads/posts/recycle-bin-preserved-' . uniqid() . '.webp';
        $absolutePath = public_path(ltrim($relativePath, '/'));
        if (!is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }
        file_put_contents($absolutePath, 'fake image bytes');

        try {
            $post = CmsPost::query()->create([
                'slug' => 'recycle-bin-post-' . uniqid(),
                'title' => 'Recycle Bin Preserved Article',
                'category' => 'General',
                'author' => 'AEC LMS',
                'content' => 'This article must survive restore.',
                'thumbnail_path' => $relativePath,
                'is_published' => true,
            ]);

            $this->get('/learn')->assertOk()->assertSee('Recycle Bin Preserved Article');
            $this->get('/learn/' . $post->slug)->assertOk()->assertSee('This article must survive restore.');

            $this->actingAs($admin)->delete('/admin/cms/posts/' . $post->id)
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSoftDeleted('cms_posts', ['id' => $post->id]);
            $this->assertFileExists($absolutePath);
            $this->get('/learn')->assertOk()->assertDontSee('Recycle Bin Preserved Article');
            $this->get('/learn/' . $post->slug)->assertNotFound();

            $this->actingAs($admin)->post('/admin/cms/posts/' . $post->id . '/restore')
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'deleted_at' => null]);
            $this->assertFileExists($absolutePath);
            $this->get('/learn')->assertOk()->assertSee('Recycle Bin Preserved Article');
            $this->get('/learn/' . $post->slug)->assertOk()->assertSee('This article must survive restore.');
        } finally {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }


    public function test_updating_a_post_without_seo_fields_preserves_existing_seo_metadata(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create([
            'slug' => 'seo-preservation-' . uniqid(),
            'title' => 'SEO Preservation Article',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Original article body.',
            'seo_title' => 'Original Search Title',
            'seo_description' => 'Original search description remains intact.',
            'is_published' => true,
        ]);

        $this->actingAs($admin)->put('/admin/cms/posts/' . $post->id, [
            'title' => 'Updated SEO Preservation Article',
            'slug' => 'seo-preservation-updated-' . uniqid(),
            'content' => 'Updated article body.',
            'is_published' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'seo_title' => 'Original Search Title',
            'seo_description' => 'Original search description remains intact.',
        ]);
    }


    public function test_restoring_a_published_historical_revision_never_republishes_a_draft(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create([
            'slug' => 'revision-publish-safety-' . uniqid(),
            'title' => 'Current Unpublished Draft',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Current draft content.',
            'is_published' => false,
        ]);

        $revisionId = DB::table('cms_post_revisions')->insertGetId([
            'post_id' => $post->id,
            'user_id' => $admin->id,
            'title' => 'Previously Published Snapshot',
            'slug' => 'previously-published-snapshot-' . uniqid(),
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Historical content that was once published.',
            'is_published' => true,
            'created_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/cms/posts/' . $post->id . '/revisions/' . $revisionId . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cms_posts', [
            'id' => $post->id,
            'title' => 'Previously Published Snapshot',
            'is_published' => false,
        ]);

        if (\\Illuminate\\Support\\Facades\\Schema::hasColumn('cms_posts', 'editorial_status')) {
            $this->assertDatabaseHas('cms_posts', [
                'id' => $post->id,
                'editorial_status' => 'draft',
                'submitted_by' => null,
                'reviewed_by' => null,
            ]);
        }
    }

}
