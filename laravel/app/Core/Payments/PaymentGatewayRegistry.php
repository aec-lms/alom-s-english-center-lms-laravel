<?php

declare(strict_types=1);

namespace App\Core\Payments;

use Closure;
use RuntimeException;

/**
 * Runtime registry for payment gateway plugins.
 * Provider plugins own their definitions and checkout adapters.
 */
class PaymentGatewayRegistry
{
    /** @var array<string, array{label:string,credentials:array<int,string>,initiate:Closure,checkout_ready:bool}> */
    private array $gateways = [];

    /** @param array<int, string> $credentials
     *  @param Closure(array<string, mixed>): array<string, mixed> $initiate
     */
    public function register(string $slug, string $label, array $credentials, Closure $initiate, bool $checkoutReady = true): void
    {
        if (!preg_match('/^gateway-[a-z0-9-]+$/', $slug)) {
            throw new RuntimeException('Invalid payment gateway plugin slug.');
        }

        $this->gateways[$slug] = [
            'label' => $label,
            'credentials' => array_values(array_unique($credentials)),
            'initiate' => $initiate,
            'checkout_ready' => $checkoutReady,
        ];
    }

    /** @return array<string, array{label:string,credentials:array<int,string>,checkout_ready:bool}> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->gateways as $slug => $gateway) {
            $definitions[$slug] = [
                'label' => $gateway['label'],
                'credentials' => $gateway['credentials'],
                'checkout_ready' => $gateway['checkout_ready'],
            ];
        }
        return $definitions;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function initiate(string $slug, array $input): array
    {
        $gateway = $this->gateways[$slug] ?? throw new RuntimeException('No checkout adapter is registered for this gateway plugin.');
        if (!$gateway['checkout_ready']) {
            throw new RuntimeException($gateway['label'] . ' checkout is not available until its official merchant adapter is implemented and verified.');
        }
        return ($gateway['initiate'])($input);
    }

    public function has(string $slug): bool
    {
        return isset($this->gateways[$slug]);
    }
}
