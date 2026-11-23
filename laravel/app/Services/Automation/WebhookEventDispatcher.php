<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Jobs\DeliverAutomationWebhook;
use App\Models\SaasTenant;
use Illuminate\Support\Facades\DB;

final class WebhookEventDispatcher
{
    public function dispatch(SaasTenant $tenant, string $eventName, array $payload): int
    {
        $deliveries = DB::transaction(function () use ($tenant, $eventName, $payload) {
            $webhooks = $tenant->webhooks()
                ->where('event_name', $eventName)
                ->where('is_active', true)
                ->get();

            return $webhooks->map(fn ($webhook) => $webhook->id
                ? $webhook->deliveries()->create([
                    'event_name' => $eventName,
                    'status' => 'queued',
                    'payload' => $payload,
                ])->id
                : null)->filter()->values();
        });

        foreach ($deliveries as $deliveryId) {
            DeliverAutomationWebhook::dispatch((int) $deliveryId);
        }

        return $deliveries->count();
    }
}
