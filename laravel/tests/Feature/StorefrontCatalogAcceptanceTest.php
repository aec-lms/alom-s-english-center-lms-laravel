<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\StorefrontCatalog\Models\StorefrontItem;
use Tests\TestCase;

class StorefrontCatalogAcceptanceTest extends TestCase
{
    public function test_admin_can_publish_edit_and_remove_product_and_ebook_catalog_items(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/storefront', [
            'type' => 'ebook',
            'title' => 'SSC English Grammar Guide',
            'category' => 'SSC English',
            'price' => 0,
            'original_price' => 500,
            'pages' => 120,
            'stock_count' => 0,
            'summary' => 'A complete grammar revision guide.',
            'thumbnail_url' => '/uploads/ssc-grammar.webp',
            'pdf_thumbnail_url' => '/uploads/ssc-grammar-cover.webp',
            'download_url' => '/private/storefront/ssc-grammar.pdf',
            'is_free' => true,
            'is_active' => true,
            'is_carousel_featured' => true,
        ])->assertSessionHasNoErrors();

        $item = StorefrontItem::query()->where('title', 'SSC English Grammar Guide')->firstOrFail();
        $this->assertSame(0.0, $item->price);
        $this->assertTrue($item->is_free);
        $this->assertSame('/private/storefront/ssc-grammar.pdf', $item->download_url);

        $this->get('/ebooks')->assertOk()->assertSee('SSC English Grammar Guide');

        $this->actingAs($admin)->put('/admin/storefront/' . $item->id, [
            'type' => 'product',
            'title' => 'Updated English Study Kit',
            'category' => 'Study Kit',
            'price' => 850,
            'original_price' => 1000,
            'stock_count' => 25,
            'summary' => 'Updated study kit details.',
            'thumbnail_url' => '/uploads/study-kit.webp',
            'is_active' => true,
            'is_carousel_featured' => false,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('storefront_items', [
            'id' => $item->id,
            'type' => 'product',
            'title' => 'Updated English Study Kit',
            'price' => 850,
            'stock_count' => 25,
        ]);

        $this->actingAs($admin)->delete('/admin/storefront/' . $item->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('storefront_items', ['id' => $item->id]);
    }
}
