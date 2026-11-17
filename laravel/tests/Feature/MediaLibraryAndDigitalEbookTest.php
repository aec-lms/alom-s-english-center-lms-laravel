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
    }

    public function test_paid_ebook_download_is_locked_until_verified_payment_and_uses_server_price(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'fees-management', 'storefront-catalog', 'payment-management', 'universal-payment', 'gateway-sslcommerz']);
        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store',
            'store_password' => 'test-secret',
            'sandbox_mode' => '1',
        ], ['store_password']);

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

        $this->actingAs($student)->get('/ebooks/protected-guide/download')->assertOk();
    }
}