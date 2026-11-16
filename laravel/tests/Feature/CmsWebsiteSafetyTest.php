<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
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

        $this->assertDatabaseHas('cms_posts', [
            'title' => 'Safe CMS Article',
            'thumbnail_path' => '/uploads/posts/article.webp',
            'pdf_path' => 'https://cdn.example.test/guide.pdf',
            'pdf_thumbnail' => '/uploads/posts/pdf-thumbs/guide.webp',
        ]);
    }
}
