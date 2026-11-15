<?php

declare(strict_types=1);

namespace Plugins\Modules\NoticeBoard\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $fillable = [
        'branch_id', 'title', 'body', 'audience_role',
        'is_pinned', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'published_at' => 'datetime',
    ];
}
