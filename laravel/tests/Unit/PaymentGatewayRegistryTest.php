<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Payments\PaymentGatewayRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaymentGatewayRegistryTest extends TestCase
{
    public function test_gateway_plugins_can_register_and_dispatch_without_core_changes(): void
    {
        $registry = new PaymentGatewayRegistry();
        $registry->register(
            'gateway-example',
            'Example Merchant Gateway',
            ['merchant_key'],
            static fn (array $input): array => [
                'gateway' => 'gateway-example',
                'transaction_ref' => 'EXAMPLE-123',
                'redirect_url' => 'https://payments.example.test/checkout',
                'amount' => $input['amount'] ?? null,
            ]
        );

        $this->assertArrayHasKey('gateway-example', $registry->definitions());
        $this->assertSame(['merchant_key'], $registry->definitions()['gateway-example']['credentials']);
        $this->assertSame([
            'gateway' => 'gateway-example',
            'transaction_ref' => 'EXAMPLE-123',
            'redirect_url' => 'https://payments.example.test/checkout',
            'amount' => 250,
        ], $registry->initiate('gateway-example', ['amount' => 250]));
    }

    public function test_gateway_without_verified_checkout_adapter_fails_closed(): void
    {
        $registry = new PaymentGatewayRegistry();
        $registry->register(
            'gateway-unverified',
            'Unverified Gateway',
            ['merchant_id'],
            static fn (array $input): array => [],
            false
        );

        $this->assertFalse($registry->definitions()['gateway-unverified']['checkout_ready']);
        $this->expectException(RuntimeException::class);
        $registry->initiate('gateway-unverified', []);
    }

    public function test_invalid_gateway_slug_is_rejected(): void
    {
        $registry = new PaymentGatewayRegistry();
        $this->expectException(RuntimeException::class);
        $registry->register('stripe', 'Invalid', [], static fn (array $input): array => []);
    }
}
