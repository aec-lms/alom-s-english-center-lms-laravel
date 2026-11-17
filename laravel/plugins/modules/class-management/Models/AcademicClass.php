<?php

declare(strict_types=1);

namespace Plugins\Modules\ClassManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicClass extends Model
{
    use SoftDeletes;

    protected $table = 'academic_classes';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'section',
        'subject',
        'room_number',
        'day_of_week',
        'start_time',
        'end_time',
        'instructor_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
