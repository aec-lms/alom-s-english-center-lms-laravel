<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseBundles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseBundleItem extends Model
{
    protected $table = 'course_bundle_courses';
    protected $fillable = ['bundle_id', 'course_id', 'position'];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(CourseBundle::class, 'bundle_id');
    }
}
