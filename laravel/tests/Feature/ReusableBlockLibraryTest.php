<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use Plugins\Modules\PageBuilder\Services\ReusableBlockLibrary;
use Tests\TestCase;

final class ReusableBlockLibraryTest extends TestCase
{
    public function test_admin_can_save_and_remove_reusable_content_blocks(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/page-builder/reusable-blocks', [
            'type' => 'heading',
            'title' => 'Homepage heading preset',
            'content' => 'Welcome to our English center',
        ])->assertRedirect()->assertSessionHas('status');

        $library = app(ReusableBlockLibrary::class);
        $blocks = $library->all();
        $this->assertCount(1, $blocks);
        $this->assertSame('heading', $blocks[0]['type']);
        $this->assertSame('Welcome to our English center', $blocks[0]['content']);

        $this->actingAs($admin)->delete('/admin/page-builder/reusable-blocks/'.$blocks[0]['id'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertCount(0, $library->all());
    }

    public function test_reusable_library_is_connected_to_page_builder_editor(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->post('/admin/page-builder/reusable-blocks', [
            'type' => 'text',
            'title' => 'Shared intro paragraph',
            'content' => 'Consistent copy across pages',
        ])->assertRedirect();
        $this->actingAs($admin)->get('/admin/page-builder')
            ->assertOk()
            ->assertSee('data-reusable-select', false)
            ->assertSee('Shared intro paragraph')
            ->assertSee('Insert reusable block');
    }

    public function test_reusable_cta_requires_safe_destination_and_invalid_urls_are_rejected(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['page-builder']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/page-builder/reusable-blocks', [
            'type' => 'button',
            'title' => 'Unsafe CTA',
            'content' => 'Click',
            'url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('url');

        $this->assertCount(0, app(ReusableBlockLibrary::class)->all());
        $this->assertDatabaseMissing('system_settings', ['key' => 'page_builder.reusable_blocks']);
    }
}
