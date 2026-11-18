<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Tests\TestCase;

class UploadedFileExtensionSecurityTest extends TestCase
{
    public function test_cms_public_image_upload_ignores_a_spoofed_php_client_extension(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);
        $admin = $this->createUserWithRole('admin');

        $image = UploadedFile::fake()->image('safe-thumbnail.jpg');
        $spoofedUpload = new UploadedFile($image->getPathname(), 'thumbnail.php', 'image/jpeg', null, true);

        $this->actingAs($admin)->post('/admin/cms/posts', [
            'title' => 'Upload Extension Security Test',
            'content' => 'A test article.',
            'thumbnail' => $spoofedUpload,
            'is_published' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $post = CmsPost::query()->where('title', 'Upload Extension Security Test')->firstOrFail();
        $this->assertMatchesRegularExpression('/\.(?:jpe?g|png|webp)$/i', (string) $post->thumbnail_path);
        $this->assertStringNotContainsString('.php', strtolower((string) $post->thumbnail_path));

        $storedPath = public_path(ltrim((string) $post->thumbnail_path, '/'));
        $this->assertFileExists($storedPath);
        @unlink($storedPath);
    }

    public function test_public_and_private_plugin_upload_handlers_do_not_use_client_filename_extensions(): void
    {
        $paths = [
            'plugins/modules/cms-website/Http/Controllers/CmsController.php',
            'plugins/modules/course-management/Http/Controllers/CourseController.php',
            'plugins/modules/course-management/Http/Controllers/LessonContentProtectionController.php',
            'plugins/modules/storefront-catalog/Http/Controllers/StorefrontController.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertIsString($source, "Unable to read {$path}");
            $this->assertStringNotContainsString('getClientOriginalExtension()', $source, "{$path} must not trust the client filename extension.");
            $this->assertStringContainsString('guessExtension()', $source, "{$path} must derive the stored extension from the detected MIME type.");
        }
    }
}
