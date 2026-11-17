<?php

declare(strict_types=1);

namespace Plugins\Modules\BatchManagement\Models;

use Illuminate\Database\Eloquent\Model;

class StudyBatch extends Model
{
    protected $table = 'study_batches';

    protected $fillable = [
        'branch_id',
        'course_title',
        'academic_level',
        'batch_code',
        'name',
        'schedule',
        'room_number',
        'max_capacity',
        'enrolled_count',
        'start_date',
        'end_date',
        'status',
    ];
}
