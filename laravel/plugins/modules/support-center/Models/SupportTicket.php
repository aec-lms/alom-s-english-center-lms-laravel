<?php

declare(strict_types=1);

namespace Plugins\\Modules\\SupportCenter\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;
use Illuminate\\Database\\Eloquent\\Relations\\HasOne;

class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'subject', 'status', 'last_message_at', 'closed_at'];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\\App\\Models\\User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class, 'ticket_id')->latestOfMany();
    }
}
