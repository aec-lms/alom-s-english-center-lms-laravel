<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Models;

use Illuminate\Database\Eloquent\Model;

class LmsNotification extends Model
{
    protected $table = 'lms_notifications';

    protected $fillable = ['user_id', 'branch_id', 'channel', 'title', 'message', 'status', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
