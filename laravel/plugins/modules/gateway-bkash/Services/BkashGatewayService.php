<?php

declare(strict_types=1);

namespace Plugins\\Modules\\GatewayBkash\\Services;

use App\\Core\\Plugins\\PluginManager;
use Illuminate\\Support\\Facades\\Http;
use RuntimeException;

class BkashGatewayService
{
    public function __construct(private readonly PluginManager $plugins) {}

    /**
     * Verify the merchant credentials by requesting an access token.
     * This is a non-charging operation; the token is deliberately not persisted.
     *
     * @return array{mode: string, authenticated: bool}
     */
    public function testCredentials(): array
    {
        $appKey = trim((string) $this->plugins->getSetting('gateway-bkash', 'app_key', ''));
        $appSecret = (string) $this->plugins->getSetting('gateway-bkash', 'app_secret', '');
        $username = trim((string) $this->plugins->getSetting('gateway-bkash', 'username', ''));
        $password = (string) $this->plugins->getSetting('gateway-bkash', 'password', '');

        if ($appKey === '' || $appSecret === '' || $username === '' || $password === '') {
            throw new RuntimeException('Enter the bKash App Key, App Secret, API Username and API Password before testing.');
        }

        $sandbox = $this->plugins->getSetting('gateway-bkash', 'sandbox_mode', '1') === '1';
        $baseUrl = $sandbox
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta';

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withHeaders([
                    'username' => $username,
                    'password' => $password,
                    'X-APP-Key' => $appKey,
                ])
                ->timeout(30)
                ->post($baseUrl . '/tokenized/checkout/token/grant', [
                    'app_key' => $appKey,
                    'app_secret' => $appSecret,
                ]);
        } catch (\\Throwable $exception) {
            throw new RuntimeException('Could not reach the selected bKash environment. Check server connectivity and try again.', previous: $exception);
        }

        $payload = $response->json();
        $success = $response->successful()
            && is_array($payload)
            && (string) ($payload['statusCode'] ?? '') === '0000'
            && !empty($payload['id_token']);

        if (!$success) {
            $providerMessage = is_array($payload) ? (string) ($payload['statusMessage'] ?? '') : '';
            throw new RuntimeException($providerMessage !== ''
                ? 'bKash credential test failed: ' . mb_substr($providerMessage, 0, 180)
                : 'bKash credential test failed. Verify the merchant credentials and selected environment.');
        }

        return ['mode' => $sandbox ? 'sandbox' : 'live', 'authenticated' => true];
    }
}
