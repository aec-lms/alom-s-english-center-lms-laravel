<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveClass extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'instructor_name',
        'platform',
        'meeting_url',
        'recording_url',
        'starts_at',
        'status',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
