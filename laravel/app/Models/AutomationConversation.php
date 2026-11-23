<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationConversation extends Model
{
    protected $table = 'automation_conversations';
    protected $fillable = ['tenant_id', 'contact_id', 'channel_id', 'assigned_user_id', 'subject', 'status', 'priority', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(SaasTenant::class, 'tenant_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(AutomationContact::class, 'contact_id'); }
    public function channel(): BelongsTo { return $this->belongsTo(AutomationChannel::class, 'channel_id'); }
    public function messages(): HasMany { return $this->hasMany(AutomationMessage::class, 'conversation_id'); }
}
