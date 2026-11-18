<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StorefrontPaymentCheckoutTest extends TestCase
{
    public function test_paid_ebook_checkout_creates_only_one_pending_session_for_a_buyer(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog', 'universal-payment', 'gateway-sslcommerz', 'commerce-cart']);

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

        $this->actingAs($buyer)
            ->get('/ebooks/atomic-checkout-guide')
            ->assertOk()
            ->assertSee('Unified Secure Checkout')
            ->assertSee('Add this eBook to your cart')
            ->assertSee('Add to Cart');

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

    public function test_failed_gateway_initialization_releases_pending_checkout_for_retry(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog', 'universal-payment', 'gateway-sslcommerz']);

        $buyer = $this->createUserWithRole('student');
        $itemId = DB::table('storefront_items')->insertGetId([
            'type' => 'ebook',
            'slug' => 'retry-checkout-guide',
            'title' => 'Retry Checkout Guide',
            'category' => 'English',
            'price' => 180,
            'original_price' => 200,
            'is_free' => false,
            'pages' => 30,
            'stock_count' => 0,
            'summary' => 'A private digital guide.',
            'download_url' => 'private:ebooks/retry-checkout-guide.pdf',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'payer_name' => $buyer->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Retry Checkout Guide',
            'gateway' => 'sslcommerz',
            'storefront_item_id' => $itemId,
        ];

        $this->actingAs($buyer)
            ->from('/ebooks/retry-checkout-guide')
            ->post('/universal-checkout', $payload)
            ->assertRedirect('/ebooks/retry-checkout-guide')
            ->assertSessionHasErrors('gateway');

        $this->assertDatabaseHas('universal_payment_transactions', [
            'user_id' => $buyer->id,
            'storefront_item_id' => $itemId,
            'status' => 'failed',
        ]);

        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store-id',
            'store_password' => 'test-store-password',
            'sandbox_mode' => '1',
        ]);
        Http::fake([
            'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gateway/checkout',
                'sessionkey' => 'retry-session-key',
            ], 200),
        ]);

        $this->actingAs($buyer)
            ->post('/universal-checkout', $payload)
            ->assertRedirect('https://sandbox.sslcommerz.com/gateway/checkout');

        $this->assertDatabaseCount('universal_payment_transactions', 2);
        $this->assertDatabaseHas('universal_payment_transactions', [
            'user_id' => $buyer->id,
            'storefront_item_id' => $itemId,
            'status' => 'pending',
            'amount' => 180,
        ]);
        Http::assertSentCount(1);
    }

    public function test_stale_pending_ebook_checkout_is_not_replaced_before_provider_reconciliation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['storefront-catalog', 'universal-payment', 'gateway-sslcommerz']);

        $buyer = $this->createUserWithRole('student');
        $itemId = DB::table('storefront_items')->insertGetId([
            'type' => 'ebook',
            'slug' => 'stale-pending-guide',
            'title' => 'Stale Pending Guide',
            'category' => 'English',
            'price' => 220,
            'original_price' => 250,
            'is_free' => false,
            'pages' => 25,
            'stock_count' => 0,
            'summary' => 'A private digital guide.',
            'download_url' => 'private:ebooks/stale-pending-guide.pdf',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('universal_payment_transactions')->insert([
            'reference' => 'UPAY-STALE-PENDING-001',
            'payer_name' => $buyer->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Stale Pending Guide',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 220,
            'status' => 'pending',
            'user_id' => $buyer->id,
            'storefront_item_id' => $itemId,
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($buyer)
            ->post('/universal-checkout', [
                'payer_name' => $buyer->name,
                'payer_contact' => '01712345678',
                'item_title' => 'Stale Pending Guide',
                'gateway' => 'sslcommerz',
                'storefront_item_id' => $itemId,
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('universal_payment_transactions', 1);
    }

    public function test_ambiguous_ebook_gateway_timeout_requires_reconciliation_before_retry(): void
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
            'slug' => 'timeout-checkout-guide',
            'title' => 'Timeout Checkout Guide',
            'category' => 'English',
            'price' => 220,
            'original_price' => 250,
            'is_free' => false,
            'pages' => 25,
            'stock_count' => 0,
            'summary' => 'A private digital guide.',
            'download_url' => 'private:ebooks/timeout-checkout-guide.pdf',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function () {
            throw new ConnectionException('Simulated provider timeout after session request.');
        });

        $payload = [
            'payer_name' => $buyer->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Timeout Checkout Guide',
            'gateway' => 'sslcommerz',
            'storefront_item_id' => $itemId,
        ];

        $this->actingAs($buyer)
            ->from('/ebooks/timeout-checkout-guide')
            ->post('/universal-checkout', $payload)
            ->assertRedirect('/ebooks/timeout-checkout-guide')
            ->assertSessionHasErrors('gateway');

        $this->assertDatabaseHas('universal_payment_transactions', [
            'user_id' => $buyer->id,
            'storefront_item_id' => $itemId,
            'status' => 'risk_review',
        ]);

        $this->actingAs($buyer)
            ->post('/universal-checkout', $payload)
            ->assertStatus(409);

        $this->assertSame(1, DB::table('universal_payment_transactions')
            ->where('user_id', $buyer->id)
            ->where('storefront_item_id', $itemId)
            ->count());
        $this->assertDatabaseMissing('storefront_item_access', [
            'storefront_item_id' => $itemId,
            'user_id' => $buyer->id,
        ]);
    }

}
