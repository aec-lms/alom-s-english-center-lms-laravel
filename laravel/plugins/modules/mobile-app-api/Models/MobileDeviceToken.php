<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileDeviceToken extends Model
{
    protected $table = 'mobile_device_tokens';

    protected $fillable = [
        'user_id',
        'platform',
        'device_name',
        'device_id',
        'app_version',
        'push_token',
        'last_seen_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
