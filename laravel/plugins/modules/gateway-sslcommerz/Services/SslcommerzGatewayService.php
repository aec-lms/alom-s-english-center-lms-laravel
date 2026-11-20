<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewaySslcommerz\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\PaymentManagement\Services\InvoicePaymentSessionReservationService;
use RuntimeException;
use Throwable;

class SslcommerzGatewayService
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PaymentSettlementService $settlement,
        private readonly InvoicePaymentSessionReservationService $reservations,
    ) {}

    public function initiateHostedCheckout(FeeInvoice $invoice, ?string $idempotencyKey = null): array
    {
        $storeId = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePass = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePass === '') {
            throw new RuntimeException('SSLCOMMERZ credentials are not configured.');
        }

        $studentIntegration = $this->plugins->isEnabled('student-management') && Schema::hasTable('students');
        if ($studentIntegration) {
            $invoice->loadMissing('student.user');
        }
        $customer = $studentIntegration ? $invoice->student?->user : null;
        $customerName = trim((string) ($customer?->name ?? $invoice->payer_name));
        $customerEmail = (string) ($customer?->email ?? $invoice->payer_email);
        $customerPhone = (string) ($customer?->phone ?? $invoice->payer_phone);
        if ($customerName === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The invoice must include a valid payer name and email (or be linked to a Student profile).');
        }
        $phone = preg_replace('/[^0-9+]/', '', $customerPhone);
        if (!$phone || strlen(preg_replace('/\D/', '', $phone)) < 7) {
            throw new RuntimeException('Add a valid customer phone number before starting SSLCOMMERZ checkout.');
        }

        $reservation = $this->reservations->reserve(
            $invoice,
            'gateway-sslcommerz',
            'SSL',
            ['channel' => 'sslcommerz_hosted'],
            $idempotencyKey,
        );
        $invoice = $reservation['invoice'];
        $tx = $reservation['transaction'];
        $remaining = $reservation['remaining'];

        if ($remaining < 10 || $remaining > 500000) {
            $this->reservations->markRejected($tx, 'amount_out_of_provider_range');
            throw new RuntimeException('SSLCOMMERZ checkout amount must be between 10.00 and 500000.00.');
        }

        if ($reservation['reused']) {
            $existingUrl = (string) ($tx->metadata['gateway_url'] ?? '');
            $host = strtolower((string) parse_url($existingUrl, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($existingUrl, PHP_URL_SCHEME));
            $expectedHost = $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1'
                ? 'sandbox.sslcommerz.com'
                : 'securepay.sslcommerz.com';
            if ($scheme !== 'https' || $host !== $expectedHost || $existingUrl === '') {
                throw new RuntimeException('An SSLCOMMERZ session is already reserved but its hosted URL is unavailable; reconciliation is required.');
            }

            return ['transaction' => $tx, 'hosted_endpoint' => $existingUrl];
        }

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $baseUrl = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
        $endpoint = $baseUrl . '/gwprocess/v4/api.php';

        $payload = [
            'store_id' => $storeId,
            'store_passwd' => $storePass,
            'total_amount' => number_format($remaining, 2, '.', ''),
            'currency' => strtoupper((string) $invoice->currency),
            'tran_id' => $tx->transaction_ref,
            'success_url' => url('/payments/sslcommerz/success'),
            'fail_url' => url('/payments/sslcommerz/failed'),
            'cancel_url' => url('/payments/sslcommerz/cancelled'),
            'ipn_url' => url('/payments/sslcommerz/ipn'),
            'cus_name' => mb_substr($customerName, 0, 50),
            'cus_email' => mb_substr($customerEmail, 0, 50),
            'cus_add1' => 'N/A',
            'cus_city' => 'N/A',
            'cus_postcode' => '0000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => mb_substr($phone, 0, 20),
            'shipping_method' => 'NO',
            'num_of_item' => '1',
            'product_name' => 'Course fee invoice ' . $invoice->invoice_number,
            'product_category' => 'Education',
            'product_profile' => 'non-physical-goods',
        ];

        $response = null;
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                if ($response->clientError()) {
                    $this->reservations->markRejected($tx, 'provider_http_4xx');
                } else {
                    $this->reservations->markUnknown($tx, 'provider_http_5xx');
                }
                throw new RuntimeException('SSLCOMMERZ session API returned an unsuccessful HTTP response.');
            }

            $data = $response->json();
            $gatewayUrl = is_array($data) ? (string) ($data['GatewayPageURL'] ?? '') : '';
            $host = strtolower((string) parse_url($gatewayUrl, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($gatewayUrl, PHP_URL_SCHEME));
            $expectedHost = $sandbox ? 'sandbox.sslcommerz.com' : 'securepay.sslcommerz.com';

            if (!is_array($data) || strtoupper((string) ($data['status'] ?? '')) !== 'SUCCESS'
                || $scheme !== 'https' || $host !== $expectedHost || $gatewayUrl === '') {
                $this->reservations->markUnknown($tx, 'invalid_success_response');
                throw new RuntimeException('SSLCOMMERZ did not return a valid hosted checkout URL; the reservation remains held for reconciliation.');
            }

            $tx = $this->reservations->markCreated($tx, [
                'session_key' => (string) ($data['sessionkey'] ?? ''),
                'gateway_url' => $gatewayUrl,
                'session_created' => true,
            ]);

            return [
                'transaction' => $tx,
                'hosted_endpoint' => $gatewayUrl,
            ];
        } catch (Throwable $exception) {
            if ($response === null) {
                $this->reservations->markUnknown($tx, 'transport_or_timeout');
            } elseif ($response->serverError()) {
                $this->reservations->markUnknown($tx, 'provider_http_5xx');
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Unable to create an SSLCOMMERZ hosted checkout session; the reservation remains held for reconciliation.', 0, $exception);
        }
    }

    public function testCredentials(): array
    {
        $storeId = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePass = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePass === '') {
            throw new RuntimeException('SSLCOMMERZ Store ID and Store Password are not configured.');
        }

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $baseUrl = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
        $response = Http::asForm()->acceptJson()->timeout(20)->post($baseUrl . '/gwprocess/v4/api.php', [
            'store_id' => $storeId,
            'store_passwd' => $storePass,
            'total_amount' => '10.00',
            'currency' => 'BDT',
            'tran_id' => 'AEC-TEST-' . strtoupper(bin2hex(random_bytes(5))),
            'success_url' => url('/payments/sslcommerz/success'),
            'fail_url' => url('/payments/sslcommerz/failed'),
            'cancel_url' => url('/payments/sslcommerz/cancelled'),
            'ipn_url' => url('/payments/sslcommerz/ipn'),
            'cus_name' => "AEC LMS Connection Test",
            'cus_email' => 'integration-test@example.com',
            'cus_add1' => 'N/A',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => '01700000000',
            'shipping_method' => 'NO',
            'num_of_item' => '1',
            'product_name' => 'Gateway connection test',
            'product_category' => 'Education',
            'product_profile' => 'non-physical-goods',
        ]);

        $url = (string) $response->json('GatewayPageURL', '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $expectedHost = $sandbox ? 'sandbox.sslcommerz.com' : 'securepay.sslcommerz.com';
        if (!$response->successful() || strtoupper((string) $response->json('status')) !== 'SUCCESS'
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || $host !== $expectedHost) {
            throw new RuntimeException('SSLCOMMERZ connection test failed. Check Store ID, Store Password and selected mode.');
        }

        return ['connected' => true, 'mode' => $sandbox ? 'sandbox' : 'live'];
    }

    public function verifyAndSettleIpn(array $payload): PaymentTransaction
    {
        $storeId = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_id', '');
        $storePass = (string) $this->plugins->getSetting('gateway-sslcommerz', 'store_password', '');
        if ($storeId === '' || $storePass === '') {
            throw new RuntimeException('SSLCOMMERZ credentials are not configured.');
        }

        $tranId = trim((string) ($payload['tran_id'] ?? ''));
        $valId = trim((string) ($payload['val_id'] ?? ''));
        if ($tranId === '' || $valId === '') {
            throw new RuntimeException('SSLCOMMERZ callback is missing its transaction or validation reference.');
        }

        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $tranId)
            ->where('gateway_slug', 'gateway-sslcommerz')
            ->firstOrFail();

        if ($tx->status === 'settled') {
            return $tx;
        }

        $sandbox = $this->plugins->getSetting('gateway-sslcommerz', 'sandbox_mode', '1') === '1';
        $baseUrl = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
        $validationUrl = $baseUrl . '/validator/api/validationserverAPI.php';

        $response = Http::acceptJson()
            ->timeout(20)
            ->get($validationUrl, [
                'val_id' => $valId,
                'store_id' => $storeId,
                'store_passwd' => $storePass,
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('SSLCOMMERZ transaction validation API returned an unsuccessful HTTP response.');
        }

        $validation = $response->json();
        if (!is_array($validation)
            || !in_array(strtoupper((string) ($validation['status'] ?? '')), ['VALID', 'VALIDATED'], true)) {
            throw new RuntimeException('SSLCOMMERZ could not validate this payment.');
        }

        if (!hash_equals($tx->transaction_ref, (string) ($validation['tran_id'] ?? ''))) {
            throw new RuntimeException('SSLCOMMERZ validation transaction reference does not match.');
        }

        if (strtoupper((string) ($validation['currency'] ?? '')) !== strtoupper((string) $tx->currency)) {
            throw new RuntimeException('SSLCOMMERZ validation currency does not match the invoice.');
        }

        if (abs((float) ($validation['amount'] ?? 0) - (float) $tx->amount) > 0.01) {
            throw new RuntimeException('SSLCOMMERZ validation amount does not match the invoice.');
        }

        if ((string) ($validation['risk_level'] ?? '0') === '1') {
            $tx->update([
                'status' => 'risk_review',
                'metadata' => array_merge($tx->metadata ?? [], ['risk_review_required' => true]),
            ]);
            throw new RuntimeException('SSLCOMMERZ marked this payment as high risk; manual review is required.');
        }

        return $this->settlement->settleTransaction($tx, $valId, [
            'ipn_verified' => true,
            'validation_status' => strtoupper((string) $validation['status']),
            'bank_transaction_id' => (string) ($validation['bank_tran_id'] ?? ''),
            'risk_level' => (string) ($validation['risk_level'] ?? '0'),
        ]);
    }

    public function markUnsuccessful(string $transactionRef, string $status): PaymentTransaction
    {
        $tx = PaymentTransaction::query()
            ->where('transaction_ref', $transactionRef)
            ->where('gateway_slug', 'gateway-sslcommerz')
            ->firstOrFail();

        // Browser return URLs are not authenticated by SSLCOMMERZ. A caller can
        // forge failed/cancelled callbacks for a known transaction reference, so
        // these callbacks must never mutate payment state. Only a verified IPN
        // or validation-server response may settle a transaction.
        return $tx;
    }
}
