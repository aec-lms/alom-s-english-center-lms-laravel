<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationMessage extends Model
{
    protected $table = 'automation_messages';
    protected $fillable = ['conversation_id', 'sender_user_id', 'direction', 'message_type', 'body', 'payload', 'provider_message_id', 'sent_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime'];
    }
}
