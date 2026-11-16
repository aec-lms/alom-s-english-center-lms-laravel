<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayCard\Services;

use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use RuntimeException;

class CardGatewayService
{
    /**
     * Visa and Mastercard are card networks, not acquiring processors.
     * This boundary intentionally refuses to create a fake pending transaction
     * until a merchant's bank/PSP-specific adapter has been installed.
     */
    public function authorizeTokenizedCheckout(FeeInvoice $invoice, string $paymentToken, string $brand, string $last4): PaymentTransaction
    {
        throw new RuntimeException(
            'No acquiring-bank/PSP adapter is installed for gateway-card. Use the Stripe plugin for supported Visa/Mastercard processing, or install the adapter supplied for your merchant acquirer.'
        );
    }

    public function verifyAndSettleWebhook(string $rawBody, string $signatureHeader, array $payload): PaymentTransaction
    {
        throw new RuntimeException(
            'Card settlement is disabled until a verified acquirer-specific webhook adapter is installed. No payment has been settled.'
        );
    }
}
