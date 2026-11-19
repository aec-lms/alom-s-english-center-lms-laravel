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
        $this->assertFalse((bool) $post->is_published, 'A newly created post must never bypass editorial approval.');
        $this->assertSame('draft', $post->editorial_status);
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
    }



    public function test_editorial_review_requires_approval_before_workflow_publication(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $submitter = $this->createUserWithRole('admin');
        $reviewer = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create(['slug' => 'editorial-review-test', 'title' => 'Editorial Review Test', 'category' => 'General', 'author' => 'Contributor', 'content' => 'Draft content', 'is_published' => false, 'editorial_status' => 'draft']);
        $this->actingAs($submitter)->post('/admin/cms/posts/' . $post->id . '/submit-review')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'editorial_status' => 'in_review', 'is_published' => false, 'submitted_by' => $submitter->id]);
        $this->actingAs($submitter)->post('/admin/cms/posts/' . $post->id . '/approve')->assertForbidden();
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'editorial_status' => 'in_review', 'is_published' => false]);
        $this->actingAs($reviewer)->post('/admin/cms/posts/' . $post->id . '/approve')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'editorial_status' => 'approved', 'is_published' => false, 'reviewed_by' => $reviewer->id]);
        $this->actingAs($reviewer)->post('/admin/cms/posts/' . $post->id . '/publish-approved')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'editorial_status' => 'published', 'is_published' => true]);
    }

    public function test_editorial_change_request_requires_and_persists_reviewer_feedback(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');
        $post = CmsPost::query()->create(['slug' => 'editorial-change-request', 'title' => 'Editorial Change Request', 'category' => 'General', 'author' => 'Contributor', 'content' => 'Draft content', 'is_published' => false, 'editorial_status' => 'in_review']);
        $this->actingAs($admin)->post('/admin/cms/posts/' . $post->id . '/request-changes', ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'editorial_status' => 'in_review']);
        $this->actingAs($admin)->post('/admin/cms/posts/' . $post->id . '/request-changes', ['review_note' => 'Please add references.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cms_posts', ['id' => $post->id, 'editorial_status' => 'changes_requested', 'review_note' => 'Please add references.']);
    }

}
