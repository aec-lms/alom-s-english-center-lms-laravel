<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveClass extends Model
{
    use SoftDeletes;

    protected $casts = ['starts_at' => 'datetime'];

    protected $fillable = [
        'course_id',
        'title',
        'instructor_name',
        'platform',
        'meeting_url',
        'recording_url',
        'starts_at',
        'capacity',
        'status',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(LiveClassParticipant::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
