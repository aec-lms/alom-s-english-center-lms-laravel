<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\StorefrontCatalog\Models\StorefrontItem;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;
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

        $this->actingAs($admin)->post('/admin/media', [
            'file' => UploadedFile::fake()->create('teacher-notes.docx', 32, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'collection' => 'documents',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $docx = DB::table('media_assets')->where('original_name', 'teacher-notes.docx')->first();
        $this->assertNotNull($docx);
        $this->assertSame('local', $docx->disk);
        Storage::disk('local')->assertExists($docx->path);
    }

    public function test_paid_ebook_download_is_locked_until_verified_payment_and_uses_server_price(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog', 'payment-management', 'universal-payment', 'gateway-sslcommerz']);
        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store',
            'store_password' => 'test-secret',
            'sandbox_mode' => '1',
        ], ['store_password']);

        $admin = $this->createUserWithRole('admin');
        $publicUrlItem = StorefrontItem::query()->create([
            'type' => 'ebook',
            'slug' => 'free-public-guide',
            'title' => 'Free Public Guide',
            'category' => 'IELTS',
            'price' => 0,
            'original_price' => 0,
            'is_free' => true,
            'pages' => 10,
            'stock_count' => 0,
            'summary' => 'A free guide with a public download URL',
            'download_url' => 'https://example.com/free-guide.pdf',
            'is_active' => true,
        ]);
        $this->actingAs($admin)->put('/admin/storefront/' . $publicUrlItem->id, [
            'type' => 'ebook',
            'title' => 'Now Paid Guide',
            'category' => 'IELTS',
            'price' => 100,
            'original_price' => 100,
            'pages' => 10,
            'stock_count' => 0,
            'summary' => 'Attempt to retain a public file after making it paid',
            'download_url' => '',
            'is_free' => false,
            'is_active' => true,
            'is_carousel_featured' => false,
        ])->assertSessionHasErrors('download_url');

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

        $validationCalls = 0;
        $expectedReference = '';
        Http::fake(function ($request) use (&$validationCalls, &$expectedReference) {
            if (str_contains($request->url(), '/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gateway.php?Q=test',
                    'sessionkey' => 'session-test',
                ]);
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $validationCalls++;

            if ($validationCalls === 1) {
                return Http::response([
                    'status' => 'FAILED',
                    'tran_id' => $expectedReference,
                    'amount' => '1.00',
                    'currency' => 'BDT',
                ]);
            }

            return Http::response([
                'status' => 'VALID',
                'tran_id' => $expectedReference,
                'amount' => '450.00',
                'currency' => 'BDT',
                'risk_level' => '0',
                'bank_tran_id' => 'BANK-123',
            ]);
        });

        $this->actingAs($student)->post('/universal-checkout', [
            'storefront_item_id' => $itemId,
            'payer_name' => $student->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Client supplied fake title',
            'gateway' => 'sslcommerz',
            'amount' => 1,
        ])->assertRedirect('https://sandbox.sslcommerz.com/gwprocess/v4/gateway.php?Q=test');

        $transaction = UniversalPaymentTransaction::query()->where('user_id', $student->id)->firstOrFail();
        $expectedReference = $transaction->reference;
        $this->assertSame(450.0, (float) $transaction->amount);
        $this->assertSame($itemId, (int) $transaction->storefront_item_id);

        $this->actingAs($student)->post('/universal-checkout', [
            'storefront_item_id' => $itemId,
            'payer_name' => $student->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Client supplied fake title',
            'gateway' => 'sslcommerz',
            'amount' => 1,
        ])->assertStatus(409);
        $this->assertSame(1, UniversalPaymentTransaction::query()
            ->where('user_id', $student->id)
            ->where('storefront_item_id', $itemId)
            ->count());
        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertForbidden();

        $this->post('/universal-payment/sslcommerz/success', [
            'tran_id' => $transaction->reference,
            'val_id' => 'INVALID-VAL',
        ])->assertRedirect('/ebooks/protected-guide');
        $this->assertDatabaseMissing('storefront_item_access', [
            'storefront_item_id' => $itemId,
            'user_id' => $student->id,
        ]);

        $this->post('/universal-payment/sslcommerz/success', [
            'tran_id' => $transaction->reference,
            'val_id' => 'VALID-VAL',
        ])->assertRedirect('/ebooks/protected-guide');

        if (!DB::table('storefront_item_access')->where('storefront_item_id', $itemId)->where('user_id', $student->id)->exists()) {
            app(StorefrontPaymentGatewayService::class)->verifySslcommerz([
                'tran_id' => $transaction->reference,
                'val_id' => 'VALID-VAL',
            ]);
        }

        $this->assertDatabaseHas('storefront_item_access', [
            'storefront_item_id' => $itemId,
            'user_id' => $student->id,
            'payment_transaction_id' => $transaction->id,
        ]);
        $this->assertSame('verified', $transaction->fresh()->status);

        $download = $this->actingAs($student)->get('/ebooks/protected-guide/download');
        $download->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Content-Type', 'application/pdf');

        DB::table('storefront_items')->where('id', $itemId)->update([
            'download_url' => 'private:../outside.pdf',
        ]);
        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertNotFound();

        app(PluginManager::class)->disable('storefront-catalog');
        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertNotFound();
    }

    public function test_media_library_supports_metadata_editing_recycle_restore_and_permanent_delete(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['media-library']);
        $admin = $this->createUserWithRole('admin');
        Storage::fake('public');
        Storage::fake('local');

        $this->actingAs($admin)->post('/admin/media', [
            'file' => UploadedFile::fake()->image('reusable-banner.jpg'),
            'collection' => 'marketing',
            'alt_text' => 'Original banner',
        ])->assertSessionHasNoErrors();

        $asset = DB::table('media_assets')->where('original_name', 'reusable-banner.jpg')->first();
        $this->assertNotNull($asset);
        $this->actingAs($admin)->patch('/admin/media/' . $asset->id, [
            'collection' => 'website banners',
            'alt_text' => 'Updated accessible banner',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('media_assets', [
            'id' => $asset->id,
            'collection' => 'website banners',
            'alt_text' => 'Updated accessible banner',
        ]);

        $this->actingAs($admin)->delete('/admin/media/' . $asset->id)->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('media_assets')->where('id', $asset->id)->value('deleted_at'));
        Storage::disk('public')->assertExists($asset->path);
        $this->actingAs($admin)->get('/admin/media?view=trash')->assertOk()->assertSee('reusable-banner.jpg');

        $this->actingAs($admin)->post('/admin/media/' . $asset->id . '/restore')->assertSessionHasNoErrors();
        $this->assertNull(DB::table('media_assets')->where('id', $asset->id)->value('deleted_at'));

        $this->actingAs($admin)->delete('/admin/media/' . $asset->id)->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete('/admin/media/' . $asset->id . '/permanent')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing($asset->path);
    }


}