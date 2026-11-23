<?php

declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class AutomationCampaign extends Model
{
    protected $table = 'automation_campaigns';
    protected $fillable = ['tenant_id', 'name', 'channel', 'message_template', 'status', 'scheduled_at', 'started_at', 'completed_at', 'audience_count', 'sent_count', 'failed_count'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
