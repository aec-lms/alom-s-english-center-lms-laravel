<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCard\Services;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;

class CardGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function authorizeTokenizedCheckout(FeeInvoice $invoice, string $paymentToken, string $brand, string $last4): PaymentTransaction
    {
        $merchantId = $this->plugins->getSetting('gateway-card', 'merchant_id', '');
        $apiSecret = $this->plugins->getSetting('gateway-card', 'api_secret', '');
        if ($merchantId === '' || $apiSecret === '') {
            throw new RuntimeException('Visa/Mastercard processor credentials are not configured.');
        }

        if (!in_array(strtolower($brand), ['visa', 'mastercard'], true)) {
            throw new RuntimeException('Only Visa and Mastercard networks are supported by gateway-card.');
        }

        if (!preg_match('/^\d{4}$/', $last4)) {
            throw new RuntimeException('Invalid masked card last4 reference.');
        }

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0) {
            throw new RuntimeException('Invoice is already settled.');
        }

        return PaymentTransaction::query()->create([
            'transaction_ref' => 'CARD-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'gateway-card',
            'amount' => $remaining,
            'currency' => $invoice->currency,
            'status' => 'initiated',
            'metadata' => [
                'card_brand' => strtolower($brand),
                'last4' => $last4,
                'token_hash' => hash('sha256', $paymentToken),
            ],
        ]);
    }

    public function verifyAndSettleWebhook(string $rawBody, string $signatureHeader, array $payload): PaymentTransaction
    {
        $webhookSecret = (string) $this->plugins->getSetting('gateway-card', 'webhook_secret', '');
        if ($webhookSecret === '') {
            throw new RuntimeException('Card webhook secret is not configured.');
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
        if (!hash_equals($expected, strtolower(trim($signatureHeader)))) {
            throw new RuntimeException('Invalid card processor webhook HMAC signature.');
        }

        $tranRef = (string) ($payload['transaction_ref'] ?? '');
        $processorRef = (string) ($payload['processor_reference'] ?? '');
        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $tranRef)
            ->where('gateway_slug', 'gateway-card')
            ->firstOrFail();

        return $this->settlement->settleTransaction($tx, $processorRef, ['webhook_verified' => true]);
    }
}
