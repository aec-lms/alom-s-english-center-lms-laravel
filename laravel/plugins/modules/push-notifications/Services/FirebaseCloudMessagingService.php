<?php

declare(strict_types=1);

namespace Plugins\Modules\PushNotifications\Services;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
use RuntimeException;

class FirebaseCloudMessagingService
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function canDeliverTo(int $userId): bool
    {
        if (!$this->plugins->isEnabled('push-notifications')
            || !$this->plugins->isEnabled('mobile-app-api')
            || !$this->plugins->isEnabled('notification-center')
            || !Schema::hasTable('mobile_device_tokens')
            || !Schema::hasTable('push_notification_deliveries')
            || !$this->credentials()) {
            return false;
        }

        return DB::table('mobile_device_tokens')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('push_token')
            ->where('push_token', '<>', '')
            ->exists();
    }

    public function deliver(LmsNotification $notification): string
    {
        if (!$this->plugins->isEnabled('push-notifications')
            || !$this->plugins->isEnabled('mobile-app-api')
            || !$this->plugins->isEnabled('notification-center')
            || !Schema::hasTable('mobile_device_tokens')
            || !Schema::hasTable('push_notification_deliveries')) {
            return 'not_configured';
        }

        $devices = DB::table('mobile_device_tokens')
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->whereNotNull('push_token')
            ->where('push_token', '<>', '')
            ->get(['id', 'push_token']);

        if ($devices->isEmpty()) {
            $alreadySent = DB::table('push_notification_deliveries')
                ->where('notification_id', $notification->id)
                ->where('status', 'sent')
                ->exists();

            return $alreadySent ? 'sent' : 'no_devices';
        }

        $credentials = $this->credentials();
        if (!$credentials) {
            return 'not_configured';
        }

        $payloadBytes = strlen(json_encode([
            'notification' => ['title' => (string) $notification->title, 'body' => (string) $notification->message],
            'data' => ['notification_id' => (string) $notification->id, 'screen' => 'notifications'],
        ], JSON_THROW_ON_ERROR));
        if ($payloadBytes > 4000) {
            return 'payload_too_large';
        }

        foreach ($devices as $device) {
            DB::table('push_notification_deliveries')->insertOrIgnore([
                'notification_id' => $notification->id,
                'device_token_id' => $device->id,
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

                $accessToken = $this->accessToken($credentials);
        $endpoint = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send';
        $sentCount = 0;

        foreach ($devices as $device) {
            $receipt = DB::table('push_notification_deliveries')
                ->where('notification_id', $notification->id)
                ->where('device_token_id', $device->id)
                ->first();

            if (!$receipt || in_array($receipt->status, ['sent', 'failed'], true)) {
                if ($receipt?->status === 'sent') {
                    $sentCount++;
                }
                continue;
            }

            DB::table('push_notification_deliveries')->where('id', $receipt->id)->update([
                'attempt_count' => (int) $receipt->attempt_count + 1,
                'updated_at' => now(),
            ]);

            $response = Http::withToken($accessToken)->timeout(20)->post($endpoint, [
                'message' => [
                    'token' => (string) $device->push_token,
                    'notification' => [
                        'title' => (string) $notification->title,
                        'body' => (string) $notification->message,
                    ],
                    'data' => [
                        'notification_id' => (string) $notification->id,
                        'screen' => 'notifications',
                    ],
                ],
            ]);

            if ($response->successful()) {
                DB::table('push_notification_deliveries')->where('id', $receipt->id)->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
                $sentCount++;
                continue;
            }

            $errorCode = data_get($response->json(), 'error.details.0.errorCode');
            if ($response->status() === 401) {
                Cache::forget($this->accessTokenCacheKey($credentials));
                DB::table('push_notification_deliveries')->where('id', $receipt->id)->update([
                    'last_error' => 'Firebase access token was rejected; a fresh token will be requested on retry.',
                    'updated_at' => now(),
                ]);
                throw new RuntimeException('Firebase access token was rejected; refresh will be attempted.');
            }

            if ($errorCode === 'UNREGISTERED') {
                DB::table('mobile_device_tokens')->where('id', $device->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
                DB::table('push_notification_deliveries')->where('id', $receipt->id)->update([
                    'status' => 'failed',
                    'last_error' => 'Device registration is no longer valid.',
                    'updated_at' => now(),
                ]);
                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                DB::table('push_notification_deliveries')->where('id', $receipt->id)->update([
                    'last_error' => 'Firebase temporarily rejected the request; it can be retried.',
                    'updated_at' => now(),
                ]);
                throw new RuntimeException('Firebase Cloud Messaging is temporarily unavailable.');
            }

            DB::table('push_notification_deliveries')->where('id', $receipt->id)->update([
                'status' => 'failed',
                'last_error' => 'Firebase rejected the push payload; inspect provider configuration.',
                'updated_at' => now(),
            ]);
        }

        return $sentCount > 0 ? 'sent' : 'failed';
    }

    private function credentials(): ?array
    {
        $projectId = trim((string) $this->plugins->getSetting('push-notifications', 'project_id', ''));
        $clientEmail = trim((string) $this->plugins->getSetting('push-notifications', 'client_email', ''));
        $privateKey = (string) $this->plugins->getSetting('push-notifications', 'private_key', '');

        if (!preg_match('/^[a-z0-9][a-z0-9-]{4,62}$/', $projectId)
            || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)
            || trim($privateKey) === '') {
            return null;
        }

        $privateKey = str_replace(["\\r\\n", "\\n"], "\n", $privateKey);
        if (!str_contains($privateKey, 'BEGIN PRIVATE KEY')) {
            return null;
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ];
    }

    private function accessToken(array $credentials): string
    {
        $cacheKey = $this->accessTokenCacheKey($credentials);
        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials): string {
            $privateKey = openssl_pkey_get_private($credentials['private_key']);
            if ($privateKey === false) {
                throw new RuntimeException('Firebase service account private key is invalid.');
            }

            $now = now()->timestamp;
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claims;

            if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign Firebase service account assertion.');
            }

            $assertion = $unsigned.'.'.$this->base64UrlEncode($signature);
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if (!$response->successful() || !is_string($response->json('access_token'))) {
                throw new RuntimeException('Firebase OAuth token request failed.');
            }

            return $response->json('access_token');
        });
    }

    private function accessTokenCacheKey(array $credentials): string
    {
        return 'fcm_oauth_token_'.hash('sha256', $credentials['project_id'].'|'.$credentials['client_email']);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
