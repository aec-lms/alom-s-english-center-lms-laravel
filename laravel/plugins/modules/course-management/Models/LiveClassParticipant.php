<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClassParticipant extends Model
{
    protected $fillable = [
        'live_class_id', 'user_id', 'joined_at', 'last_seen_at', 'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function liveClass(): BelongsTo
    {
        return $this->belongsTo(LiveClass::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
