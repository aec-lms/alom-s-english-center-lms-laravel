<?php

declare(strict_types=1);

namespace Plugins\Modules\GatewayBkash\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\UniversalPayment\Services\StorefrontPaymentGatewayService;
use RuntimeException;

class BkashGatewayService
{
    public function __construct(private readonly PluginManager $plugins) {}

    private function baseUrl(): string
    {
        return $this->plugins->getSetting('gateway-bkash', 'sandbox_mode', '1') === '1'
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta';
    }

    private function credentials(): array
    {
        $credentials = [
            'app_key' => trim((string) $this->plugins->getSetting('gateway-bkash', 'app_key', '')),
            'app_secret' => (string) $this->plugins->getSetting('gateway-bkash', 'app_secret', ''),
            'username' => trim((string) $this->plugins->getSetting('gateway-bkash', 'username', '')),
            'password' => (string) $this->plugins->getSetting('gateway-bkash', 'password', ''),
        ];
        foreach ($credentials as $value) {
            if ($value === '') {
                throw new RuntimeException('Configure all four bKash merchant credentials before checkout.');
            }
        }
        return $credentials;
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'payment.gateway-bkash.token.' . hash('sha256', $credentials['app_key'] . '|' . $this->baseUrl());
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asJson()->acceptJson()->withHeaders([
                'username' => $credentials['username'],
                'password' => $credentials['password'],
                'X-APP-Key' => $credentials['app_key'],
            ])->timeout(30)->post($this->baseUrl() . '/tokenized/checkout/token/grant', [
                'app_key' => $credentials['app_key'],
                'app_secret' => $credentials['app_secret'],
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Could not connect to bKash token service.', 0, $exception);
        }

        $data = $response->json();
        if (!$response->successful() || !is_array($data)
            || (string) ($data['statusCode'] ?? '') !== '0000'
            || empty($data['id_token'])) {
            throw new RuntimeException('bKash authentication failed. Check merchant credentials and environment.');
        }

        $ttl = max(60, min(3500, (int) ($data['expires_in'] ?? 3300) - 60));
        Cache::put($cacheKey, (string) $data['id_token'], now()->addSeconds($ttl));
        return (string) $data['id_token'];
    }

    private function api(string $method, string $path, array $payload = []): array
    {
        $credentials = $this->credentials();
        $request = Http::acceptJson()->withToken($this->accessToken())
            ->withHeaders(['X-APP-Key' => $credentials['app_key']])
            ->timeout(30);
        $response = strtoupper($method) === 'GET'
            ? $request->get($this->baseUrl() . $path, $payload)
            : $request->asJson()->post($this->baseUrl() . $path, $payload);
        $data = $response->json();
        if (!$response->successful() || !is_array($data)) {
            throw new RuntimeException('bKash API request failed; payment remains unverified.');
        }
        return $data;
    }

    public function initiateUniversalCheckout(UniversalPaymentTransaction $transaction, object $item, object $user, string $payerContact): array
    {
        if (strtoupper((string) $transaction->currency) !== 'BDT') {
            throw new RuntimeException('bKash checkout accepts BDT transactions only.');
        }
        $phone = preg_replace('/[^0-9+]/', '', $payerContact);
        if (strlen(preg_replace('/\D/', '', (string) $phone)) < 7
            || !filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid payer phone number and account email are required for bKash.');
        }

        $data = $this->api('POST', '/tokenized/checkout/create', [
            'mode' => '0011',
            'payerReference' => mb_substr((string) $phone, 0, 30),
            'callbackURL' => url('/universal-payment/bkash/callback'),
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => (string) $transaction->reference,
        ]);

        $paymentId = (string) ($data['paymentID'] ?? '');
        $checkoutUrl = (string) ($data['bkashURL'] ?? $data['paymentURL'] ?? '');
        $expectedHost = strtolower((string) parse_url($this->baseUrl(), PHP_URL_HOST));
        if ((string) ($data['statusCode'] ?? '') !== '0000'
            || $paymentId === ''
            || strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) !== 'https'
            || strtolower((string) parse_url($checkoutUrl, PHP_URL_HOST)) !== $expectedHost) {
            throw new RuntimeException('bKash did not return a valid hosted checkout session.');
        }

        $notes = json_decode((string) $transaction->notes, true);
        $notes = is_array($notes) ? $notes : [];
        $transaction->update([
            'external_trx_id' => $paymentId,
            'notes' => json_encode(array_merge($notes, [
                'provider' => 'bkash',
                'bkash_payment_id' => $paymentId,
                'bkash_mode' => $this->plugins->getSetting('gateway-bkash', 'sandbox_mode', '1') === '1' ? 'sandbox' : 'live',
            ]), JSON_THROW_ON_ERROR),
        ]);

        return [
            'gateway' => 'gateway-bkash',
            'transaction_ref' => $transaction->reference,
            'redirect_url' => $checkoutUrl,
            'payment_id' => $paymentId,
        ];
    }

    public function verifyUniversalCallback(string $paymentId): UniversalPaymentTransaction
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            throw new RuntimeException('bKash callback is missing paymentID.');
        }

        $transaction = UniversalPaymentTransaction::query()
            ->where('gateway', 'bkash')
            ->where('external_trx_id', $paymentId)
            ->firstOrFail();
        if ($transaction->status === 'verified') {
            return $transaction;
        }
        if (in_array($transaction->status, ['failed', 'cancelled', 'refunded', 'reversed', 'rejected', 'chargeback'], true)) {
            throw new RuntimeException('This bKash transaction is already in a terminal state.');
        }

        $data = $this->api('GET', '/tokenized/checkout/payment/status', ['paymentID' => $paymentId]);
        if (strcasecmp((string) ($data['transactionStatus'] ?? ''), 'Completed') !== 0) {
            $data = $this->api('POST', '/tokenized/checkout/execute', ['paymentID' => $paymentId]);
        }

        $merchantInvoice = (string) ($data['merchantInvoiceNumber'] ?? '');
        $trxId = (string) ($data['trxID'] ?? $data['trxId'] ?? '');
        if (strcasecmp((string) ($data['transactionStatus'] ?? ''), 'Completed') !== 0
            || ($merchantInvoice !== '' && !hash_equals((string) $transaction->reference, $merchantInvoice))
            || $trxId === ''
            || abs((float) ($data['amount'] ?? 0) - (float) $transaction->amount) > 0.01
            || strtoupper((string) ($data['currency'] ?? '')) !== strtoupper((string) $transaction->currency)) {
            throw new RuntimeException('bKash payment status, reference, amount or currency did not match. No access was granted.');
        }

        return app(StorefrontPaymentGatewayService::class)->settleVerifiedGatewayPayment(
            $transaction,
            $trxId,
            ['provider' => 'bkash', 'bkash_payment_id' => $paymentId, 'transaction_status' => 'Completed']
        );
    }

    public function testCredentials(): array
    {
        $this->accessToken();
        return [
            'mode' => $this->plugins->getSetting('gateway-bkash', 'sandbox_mode', '1') === '1' ? 'sandbox' : 'live',
            'authenticated' => true,
        ];
    }
}
