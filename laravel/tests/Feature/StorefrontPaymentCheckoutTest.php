<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StorefrontPaymentCheckoutTest extends TestCase
{
    public function test_paid_ebook_checkout_creates_only_one_pending_session_for_a_buyer(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog', 'universal-payment', 'gateway-sslcommerz']);

        $buyer = $this->createUserWithRole('student');
        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store-id',
            'store_password' => 'test-store-password',
            'sandbox_mode' => '1',
        ]);

        $itemId = DB::table('storefront_items')->insertGetId([
            'type' => 'ebook',
            'slug' => 'atomic-checkout-guide',
            'title' => 'Atomic Checkout Guide',
            'category' => 'English',
            'price' => 250,
            'original_price' => 300,
            'is_free' => false,
            'pages' => 40,
            'stock_count' => 0,
            'summary' => 'A private digital guide.',
            'download_url' => 'private:ebooks/atomic-checkout-guide.pdf',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gateway/checkout',
                'sessionkey' => 'test-session-key',
            ], 200),
        ]);

        $payload = [
            'payer_name' => $buyer->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Atomic Checkout Guide',
            'gateway' => 'sslcommerz',
            'storefront_item_id' => $itemId,
        ];

        $this->actingAs($buyer)
            ->post('/universal-checkout', $payload)
            ->assertRedirect('https://sandbox.sslcommerz.com/gateway/checkout');

        $this->assertDatabaseCount('universal_payment_transactions', 1);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'user_id' => $buyer->id,
            'storefront_item_id' => $itemId,
            'status' => 'pending',
            'amount' => 250,
        ]);

        $this->actingAs($buyer)
            ->post('/universal-checkout', $payload)
            ->assertStatus(409);

        $this->assertDatabaseCount('universal_payment_transactions', 1);
        Http::assertSentCount(1);
    }
}
