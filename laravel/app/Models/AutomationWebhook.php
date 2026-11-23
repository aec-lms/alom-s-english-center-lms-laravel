<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationWebhook extends Model
{
    protected $table = 'automation_webhooks';
    protected $fillable = ['tenant_id', 'name', 'event_name', 'target_url', 'secret_encrypted', 'is_active', 'last_triggered_at'];

    public function deliveries(): HasMany\n    {\n        return $this->hasMany(AutomationWebhookDelivery::class, 'webhook_id');\n    }\n\n    protected function casts(): array
    {
        return ['secret_encrypted' => 'encrypted', 'is_active' => 'boolean', 'last_triggered_at' => 'datetime'];
    }
}
