<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutomationWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeliverAutomationWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 20;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $delivery = AutomationWebhookDelivery::query()->with('webhook')->findOrFail($this->deliveryId);
        $webhook = $delivery->webhook;
        if (!$webhook || !$webhook->is_active) {
            $delivery->update(['status' => 'cancelled', 'last_error' => 'Webhook is disabled or missing.']);
            return;
        }

        $host = strtolower((string) parse_url($webhook->target_url, PHP_URL_HOST));
        $ips = $host !== '' ? gethostbynamel($host) : false;
        if (!str_starts_with(strtolower($webhook->target_url), 'https://') || !$ips) {
            throw new RuntimeException('Webhook host could not be resolved to a public address.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $delivery->update(['status' => 'failed', 'last_error' => 'Private or reserved webhook destination blocked.']);
                return;
            }
        }

        $secret = $webhook->secret_encrypted;
        if (!$secret) {
            $delivery->update(['status' => 'failed', 'last_error' => 'Webhook signing secret is missing.']);
            return;
        }
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $secret);
        $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'last_error' => null]);

        try {
            $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-AEC-Event' => $delivery->event_name,
                    'X-AEC-Signature' => 'sha256='.$signature,
                    'X-AEC-Delivery' => (string) $delivery->id,
                ])->withBody($body, 'application/json')->post($webhook->target_url);

            $delivery->update([
                'status' => $response->successful() ? 'delivered' : 'failed',
                'response_code' => $response->status(),
                'delivered_at' => $response->successful() ? now() : null,
                'last_error' => $response->successful() ? null : 'Webhook endpoint returned HTTP '.$response->status(),
            ]);
            if (!$response->successful()) {
                throw new RuntimeException('Webhook delivery failed with HTTP '.$response->status());
            }
            $webhook->update(['last_triggered_at' => now()]);
        } catch (\Throwable $exception) {
            $delivery->update(['status' => 'retrying', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }
}
