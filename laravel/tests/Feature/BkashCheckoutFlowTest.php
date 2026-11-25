<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Modules\GatewayBkash\Services\BkashGatewayService;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;
use Tests\TestCase;

class BkashCheckoutFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment', 'gateway-bkash']);
        Cache::flush();

        app(PluginManager::class)->saveSettings('gateway-bkash', [
            'enabled' => '1',
            'sandbox_mode' => '1',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'username' => 'test-merchant',
            'password' => 'test-password',
        ]);
    }

    public function test_bkash_creates_a_hosted_checkout_and_keeps_payment_pending_until_callback_verification(): void
    {
        $transaction = $this->pendingTransaction(null);
        Http::fake([
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant' => Http::response([
                'statusCode' => '0000', 'id_token' => 'sandbox-id-token', 'expires_in' => 3600,
            ], 200),
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/create' => Http::response([
                'statusCode' => '0000',
                'paymentID' => 'PAYMENT-123',
                'bkashURL' => 'https://tokenized.sandbox.bka.sh/checkout/PAYMENT-123',
            ], 200),
        ]);

        $result = app(BkashGatewayService::class)->initiateUniversalCheckout(
            $transaction,
            (object) ['title' => 'English e-book'],
            (object) ['email' => 'student@example.com'],
            '01700000000'
        );

        $this->assertSame('https://tokenized.sandbox.bka.sh/checkout/PAYMENT-123', $result['redirect_url']);
        $this->assertSame('PAYMENT-123', $transaction->fresh()->external_trx_id);
        $this->assertSame('pending', $transaction->fresh()->status);
        Http::assertSent(fn (ClientRequest $request): bool =>
            str_ends_with($request->url(), '/tokenized/checkout/create')
            && $request->data()['merchantInvoiceNumber'] === $transaction->reference
            && $request->data()['amount'] === '100.00'
            && $request->hasHeader('X-APP-Key', 'test-app-key')
        );
    }

    public function test_bkash_callback_queries_provider_and_settles_only_matching_completed_payment(): void
    {
        $transaction = $this->pendingTransaction('PAYMENT-456');
        Http::fake([
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant' => Http::response([
                'statusCode' => '0000', 'id_token' => 'sandbox-id-token', 'expires_in' => 3600,
            ], 200),
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/payment/status*' => Http::response([
                'transactionStatus' => 'Completed',
                'paymentID' => 'PAYMENT-456',
                'trxID' => 'BKASH-TRX-456',
                'merchantInvoiceNumber' => $transaction->reference,
                'amount' => '100.00',
                'currency' => 'BDT',
            ], 200),
        ]);

        $settlement = Mockery::mock(StorefrontPaymentGatewayService::class);
        $settlement->shouldReceive('settleVerifiedGatewayPayment')
            ->once()
            ->andReturn($transaction);
        $this->app->instance(StorefrontPaymentGatewayService::class, $settlement);

        $result = app(BkashGatewayService::class)->verifyUniversalCallback('PAYMENT-456');
        $this->assertSame($transaction->id, $result->id);
        Http::assertSent(fn (ClientRequest $request): bool =>
            str_contains($request->url(), '/tokenized/checkout/payment/status')
            && str_contains($request->url(), 'paymentID=PAYMENT-456')
        );
    }

    public function test_bkash_amount_mismatch_never_reaches_settlement(): void
    {
        $transaction = $this->pendingTransaction('PAYMENT-789');
        Http::fake([
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant' => Http::response([
                'statusCode' => '0000', 'id_token' => 'sandbox-id-token', 'expires_in' => 3600,
            ], 200),
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/payment/status*' => Http::response([
                'transactionStatus' => 'Completed',
                'paymentID' => 'PAYMENT-789',
                'trxID' => 'BKASH-TRX-789',
                'merchantInvoiceNumber' => $transaction->reference,
                'amount' => '90.00',
                'currency' => 'BDT',
            ], 200),
        ]);

        $settlement = Mockery::mock(StorefrontPaymentGatewayService::class);
        $settlement->shouldNotReceive('settleVerifiedGatewayPayment');
        $this->app->instance(StorefrontPaymentGatewayService::class, $settlement);

        try {
            app(BkashGatewayService::class)->verifyUniversalCallback('PAYMENT-789');
            $this->fail('An amount mismatch must not settle the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('amount or currency', $exception->getMessage());
        }
    }

    private function pendingTransaction(?string $paymentId): UniversalPaymentTransaction
    {
        return UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => 'Test Student',
            'payer_contact' => '01700000000',
            'item_title' => 'English e-book',
            'gateway' => 'bkash',
            'currency' => 'BDT',
            'amount' => 100,
            'external_trx_id' => $paymentId,
            'status' => 'pending',
        ]);
    }
}
