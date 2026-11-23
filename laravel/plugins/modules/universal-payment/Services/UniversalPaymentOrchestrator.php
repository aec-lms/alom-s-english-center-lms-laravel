<?php

declare(strict_types=1);

namespace Plugins\Modules\UniversalPayment\Services;

use App\Core\Payments\PaymentGatewayRegistry;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use RuntimeException;

class UniversalPaymentOrchestrator
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentGatewayRegistry $gateways,
    ) {}

    /** @return array<string, array{label:string,available:bool,status:string,missing_credentials:array<int,string>}> */
    public function gatewayStatus(): array
    {
        $enabledMethods = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->plugins->getSetting('universal-payment', 'enabled_gateways', 'sslcommerz,stripe,paypal'))
        )));
        $statuses = [];

        foreach ($this->gateways->definitions() as $slug => $definition) {
            $method = str_replace('-', '_', substr($slug, strlen('gateway-')));
            $methodEnabled = in_array($method, $enabledMethods, true);
            $pluginEnabled = $this->plugins->isEnabled($slug);
            $missing = [];
            $credentialKeys = $definition['manual'] ? [] : $definition['credentials'];
            foreach ($credentialKeys as $key) {
                if (($this->plugins->getSetting($slug, $key, '') ?? '') === '') {
                    $missing[] = $key;
                }
            }

            $available = $methodEnabled && $pluginEnabled && $missing === [] && ($definition['checkout_ready'] || $definition['manual']);
            $status = !$methodEnabled
                ? 'disabled_in_universal_payment'
                : (!$pluginEnabled
                    ? 'disabled'
                    : ($missing !== []
                        ? 'not_configured'
                        : ($definition['manual'] ? 'manual_review' : (!$definition['checkout_ready'] ? 'processor_adapter_required' : 'ready'))));

            $statuses[$slug] = [
                'label' => $definition['label'],
                'available' => $available,
                'status' => $status,
                'missing_credentials' => $missing,
            ];
        }

        return $statuses;
    }

    public function assertGatewayReady(string $slug): void
    {
        $status = $this->gatewayStatus()[$slug] ?? throw new RuntimeException('Unsupported or unregistered payment gateway plugin.');
        if (!$status['available']) {
            $reason = match ($status['status']) {
                'disabled_in_universal_payment' => 'Gateway is disabled in Universal Payment settings.',
                'disabled' => 'Gateway plugin is not enabled.',
                'processor_adapter_required' => 'The gateway plugin has no verified checkout adapter yet.',
                'manual_review' => 'This method accepts receipts and requires authorized staff verification.',
                default => 'Missing credentials: ' . implode(', ', $status['missing_credentials']),
            };
            throw new RuntimeException($status['label'] . ' is unavailable. ' . $reason);
        }
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function initiate(string $slug, FeeInvoice $invoice, array $input = []): array
    {
        $this->assertGatewayReady($slug);

        return $this->gateways->initiate($slug, array_merge($input, ['invoice' => $invoice]));
    }
}
