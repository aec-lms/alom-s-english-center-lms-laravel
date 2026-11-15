<?php

declare(strict_types=1);

namespace Plugins\Modules\EmailNotifications\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $table = 'email_logs';

    protected $fillable = [
        'recipient_email',
        'subject',
        'body_excerpt',
        'template_key',
        'status',
        'dispatched_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
    ];
}
