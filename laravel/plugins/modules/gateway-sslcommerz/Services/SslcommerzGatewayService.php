<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Services;

use App\Core\Plugins\PluginManager;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use RuntimeException;

class SslcommerzGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function initiateHostedCheckout(FeeInvoice $invoice): array
    {
        $storeId = $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePass = $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePass === '') {
            throw new RuntimeException('SSLCOMMERZ credentials are not configured.');
        }

        $remaining = round((float) $invoice->net_amount - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0) {
            throw new RuntimeException('Invoice is already fully settled.');
        }

        $tx = PaymentTransaction::query()->create([
            'transaction_ref' => 'SSL-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => $remaining,
            'currency' => $invoice->currency,
            'status' => 'initiated',
            'metadata' => ['channel' => 'sslcommerz_hosted'],
        ]);

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $endpoint = $sandbox
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';

        return [
            'transaction' => $tx,
            'hosted_endpoint' => $endpoint,
            'payload' => [
                'store_id' => $storeId,
                'tran_id' => $tx->transaction_ref,
                'total_amount' => $remaining,
                'currency' => $invoice->currency,
            ],
        ];
    }

    public function verifyAndSettleIpn(array $payload): PaymentTransaction
    {
        $storePass = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storePass === '') {
            throw new RuntimeException('SSLCOMMERZ store password not configured.');
        }

        $tranId = (string) ($payload['tran_id'] ?? '');
        $valId = (string) ($payload['val_id'] ?? '');
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $verifySign = strtolower((string) ($payload['verify_sign'] ?? ''));
        $verifyKey = (string) ($payload['verify_key'] ?? '');

        if ($tranId === '' || $valId === '' || $verifySign === '' || $verifyKey === '') {
            throw new RuntimeException('Incomplete SSLCOMMERZ IPN payload.');
        }

        // SSLCOMMERZ verify_key / store_passwd hash verification
        $hashString = '';
        foreach (explode(',', $verifyKey) as $key) {
            $k = trim($key);
            if ($k === 'store_passwd') {
                $hashString .= $k . '=' . md5($storePass) . '&';
            } elseif (isset($payload[$k])) {
                $hashString .= $k . '=' . $payload[$k] . '&';
            }
        }
        $expectedSign = md5(rtrim($hashString, '&'));
        if (!hash_equals($expectedSign, $verifySign)) {
            throw new RuntimeException('SSLCOMMERZ IPN signature verification failed.');
        }

        if ($status !== 'VALID' && $status !== 'VALIDATED') {
            throw new RuntimeException("SSLCOMMERZ transaction status not valid: {$status}");
        }

        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $tranId)
            ->where('gateway_slug', 'gateway-sslcommerz')
            ->firstOrFail();

        if ((float) ($payload['amount'] ?? 0) + 0.01 < (float) $tx->amount) {
            throw new RuntimeException('SSLCOMMERZ IPN amount mismatch.');
        }

        return $this->settlement->settleTransaction($tx, $valId, ['ipn_verified' => true]);
    }
}
