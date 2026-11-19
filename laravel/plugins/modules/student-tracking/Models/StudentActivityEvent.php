<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentTracking\Models;

use Illuminate\Database\Eloquent\Model;

class StudentActivityEvent extends Model
{
    protected $table = 'student_activity_events';

    protected $fillable = [
        'user_id', 'student_id', 'branch_id', 'actor_id', 'event_type', 'subject_type', 'subject_id',
        'duration_seconds', 'metadata', 'occurred_at',
    ];

    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime'];
}
