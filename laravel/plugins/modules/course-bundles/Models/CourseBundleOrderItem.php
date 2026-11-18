<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseBundles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseBundleOrderItem extends Model
{
    protected $table = 'course_bundle_order_courses';
    protected $fillable = ['order_id', 'course_id', 'course_title', 'position'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CourseBundleOrder::class, 'order_id');
    }
}
