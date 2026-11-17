<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsNotification extends Model
{
    protected $table = 'lms_notifications';

    protected $fillable = ['user_id', 'branch_id', 'channel', 'title', 'message', 'status', 'read_at', 'attempt_count', 'last_error', 'processed_at'];

    protected $casts = ['read_at' => 'datetime', 'processed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
