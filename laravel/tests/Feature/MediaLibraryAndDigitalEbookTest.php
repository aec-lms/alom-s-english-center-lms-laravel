<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Plugins\Modules\StorefrontCatalog\Models\StorefrontItem;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Tests\TestCase;

class MediaLibraryAndDigitalEbookTest extends TestCase
{
    public function test_media_library_uploads_images_and_keeps_documents_private(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['media-library']);
        $admin = $this->createUserWithRole('admin');

        Storage::fake('public');
        Storage::fake('local');

        $this->actingAs($admin)->post('/admin/media', [
            'file' => UploadedFile::fake()->image('course-cover.jpg'),
            'collection' => 'courses',
            'alt_text' => 'Course cover',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $image = DB::table('media_assets')->where('original_name', 'course-cover.jpg')->first();
        $this->assertNotNull($image);
        $this->assertSame('public', $image->disk);
        Storage::disk('public')->assertExists($image->path);

        $this->actingAs($admin)->post('/admin/media', [
            'file' => UploadedFile::fake()->create('worksheet.pdf', 32, 'application/pdf'),
            'collection' => 'worksheets',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $pdf = DB::table('media_assets')->where('original_name', 'worksheet.pdf')->first();
        $this->assertNotNull($pdf);
        $this->assertSame('local', $pdf->disk);
        Storage::disk('local')->assertExists($pdf->path);
    }

    public function test_paid_ebook_download_is_locked_until_verified_payment_and_uses_server_price(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog', 'payment-management', 'universal-payment']);

        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        Storage::fake('local');
        $pdfPath = 'digital-products/private-guide.pdf';
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 protected test document');

        $itemId = StorefrontItem::query()->create([
            'type' => 'ebook',
            'slug' => 'protected-guide',
            'title' => 'Protected IELTS Guide',
            'category' => 'IELTS',
            'price' => 450,
            'original_price' => 600,
            'is_free' => false,
            'pages' => 80,
            'stock_count' => 0,
            'summary' => 'Paid protected guide',
            'download_url' => 'private:' . $pdfPath,
            'is_active' => true,
        ])->id;

        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertForbidden();

        $this->actingAs($student)->post('/universal-checkout', [
            'storefront_item_id' => $itemId,
            'payer_name' => $student->name,
            'payer_contact' => $student->email,
            'item_title' => 'Client supplied fake title',
            'gateway' => 'bkash',
            'amount' => 1,
            'external_trx_id' => 'TXN-BOOK-001',
        ])->assertSessionHasNoErrors();

        $transaction = UniversalPaymentTransaction::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame(450.0, (float) $transaction->amount);
        $this->assertSame($itemId, (int) $transaction->storefront_item_id);
        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertForbidden();

        $this->actingAs($admin)->post("/plugins/universal-payment/transactions/{$transaction->id}/status", [
            'status' => 'verified',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('storefront_item_access', [
            'storefront_item_id' => $itemId,
            'user_id' => $student->id,
            'payment_transaction_id' => $transaction->id,
        ]);

        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertOk();
    }
}