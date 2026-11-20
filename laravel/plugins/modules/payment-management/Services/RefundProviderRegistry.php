<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use RuntimeException;

class RefundProviderRegistry
{
    private const ADAPTERS = [
        'gateway-stripe' => \Plugins\Modules\GatewayStripe\Services\StripeRefundAdapter::class,
        'gateway-paypal' => \Plugins\Modules\GatewayPaypal\Services\PaypalRefundAdapter::class,
        'gateway-sslcommerz' => \Plugins\Modules\GatewaySslcommerz\Services\SslcommerzRefundAdapter::class,
    ];

    public function __construct(private readonly PluginManager $plugins) {}

    public function forTransaction(PaymentTransaction $transaction, bool $reconciliation = false): RefundProviderAdapter
    {
        $gateway = (string) $transaction->gateway_slug;
        $adapterClass = self::ADAPTERS[$gateway] ?? null;
        if ($adapterClass === null || !class_exists($adapterClass)) {
            throw new RuntimeException('No verified refund adapter is installed for this payment gateway.');
        }

        // Turning off new refunds must never strand an already-submitted
        // refund. Reconciliation remains available while the adapter and
        // stored merchant credentials are present.
        if (!$reconciliation && (!$this->plugins->isEnabled($gateway)
            || $this->plugins->getSetting($gateway, 'refunds_enabled', '0') !== '1')) {
            throw new RuntimeException('Provider refunds are disabled until merchant sandbox and refund capability are verified.');
        }

        $adapter = app($adapterClass);
        if (!$adapter instanceof RefundProviderAdapter) {
            throw new RuntimeException('The configured refund adapter does not implement the required contract.');
        }

        return $adapter;
    }
}
