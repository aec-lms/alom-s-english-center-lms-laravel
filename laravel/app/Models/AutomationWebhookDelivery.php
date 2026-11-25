<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWebhookDelivery extends Model
{
    protected $table = 'automation_webhook_deliveries';
    protected $fillable = ['webhook_id', 'event_name', 'status', 'attempts', 'response_code', 'payload', 'last_error', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(AutomationWebhook::class, 'webhook_id');
    }
}
