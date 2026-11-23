<?php

declare(strict_types=1);

namespace App\Core\Payments;

use Closure;
use RuntimeException;

/**
 * Runtime registry for payment gateway plugins.
 *
 * A gateway plugin registers its own definition and checkout callback. The
 * Payment Core depends only on this registry, never on provider-specific
 * service classes.
 */
class PaymentGatewayRegistry
{
    /** @var array<string, array{label:string,credentials:array<int,string>,initiate:Closure}> */
    private array $gateways = [];

    /**
     * @param array<int, string> $credentials
     * @param Closure(array<string, mixed>): array<string, mixed> $initiate
     */
    public function register(string $slug, string $label, array $credentials, Closure $initiate): void
    {
        if (!preg_match('/^gateway-[a-z0-9-]+$/', $slug)) {
            throw new RuntimeException('Invalid payment gateway plugin slug.');
        }

        $this->gateways[$slug] = [
            'label' => $label,
            'credentials' => array_values(array_unique($credentials)),
            'initiate' => $initiate,
        ];
    }

    /** @return array<string, array{label:string,credentials:array<int,string>}> */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->gateways as $slug => $gateway) {
            $definitions[$slug] = [
                'label' => $gateway['label'],
                'credentials' => $gateway['credentials'],
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
        return ($gateway['initiate'])($input);
    }

    public function has(string $slug): bool
    {
        return isset($this->gateways[$slug]);
    }
}
