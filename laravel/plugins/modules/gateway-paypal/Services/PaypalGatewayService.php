<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayPaypal\Services;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;

class PaypalGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function createOrder(FeeInvoice $invoice, ?string $idempotencyKey = null): array
    {
        $clientId = (string) $this->plugins->getSetting('gateway-paypal', 'client_id', '');
        $clientSecret = (string) $this->plugins->getSetting('gateway-paypal', 'client_secret', '');
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('PayPal client credentials are not configured.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = PaymentTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('gateway_slug', 'gateway-paypal')
                ->where('metadata->idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return [
                    'transaction' => $existing,
                    'paypal_order_id' => (string) ($existing->metadata['paypal_order_id'] ?? ''),
                    'approval_url' => (string) ($existing->metadata['approval_url'] ?? ''),
                ];
            }
        }

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0) {
            throw new RuntimeException('Invoice is already fully settled.');
        }

        $paypalOrderId = 'PP-ORD-' . strtoupper(bin2hex(random_bytes(6)));
        $sandbox = $this->plugins->getSetting('gateway-paypal', 'sandbox_mode', '1') === '1';
        $approvalUrl = ($sandbox ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com')
            . '/checkoutnow?token=' . $paypalOrderId;

        $tx = PaymentTransaction::query()->create([
            'transaction_ref' => 'PYPL-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'gateway-paypal',
            'amount' => $remaining,
            'currency' => $invoice->currency,
            'status' => 'initiated',
            'metadata' => [
                'paypal_order_id' => $paypalOrderId,
                'approval_url' => $approvalUrl,
                'idempotency_key' => $idempotencyKey,
                'sandbox' => $sandbox,
            ],
        ]);

        return [
            'transaction' => $tx,
            'paypal_order_id' => $paypalOrderId,
            'approval_url' => $approvalUrl,
        ];
    }

    public function verifyAndSettleWebhook(string $rawBody, string $signatureHeader, array $payload): PaymentTransaction
    {
        $webhookSecret = (string) $this->plugins->getSetting('gateway-paypal', 'webhook_id', '');
        if ($webhookSecret === '') {
            throw new RuntimeException('PayPal webhook signing secret is not configured.');
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
        if (!hash_equals($expected, strtolower(trim($signatureHeader)))) {
            throw new RuntimeException('Invalid PayPal webhook signature.');
        }

        $tranRef = (string) ($payload['transaction_ref'] ?? '');
        $captureId = (string) ($payload['capture_id'] ?? 'CAP-' . bin2hex(random_bytes(5)));

        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $tranRef)
            ->where('gateway_slug', 'gateway-paypal')
            ->firstOrFail();

        return $this->settlement->settleTransaction($tx, $captureId, ['paypal_webhook_verified' => true]);
    }
}
