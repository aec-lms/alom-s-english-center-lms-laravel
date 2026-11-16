<?php

declare(strict_types=1);

namespace Plugins\Modules\BatchManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudyBatch extends Model
{
    use SoftDeletes;

    protected $table = 'study_batches';

    protected $fillable = [
        'branch_id',
        'course_title',
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
