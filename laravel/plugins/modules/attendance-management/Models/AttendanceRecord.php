<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'batch_id', 'attendance_batch_mapping_id', 'student_id', 'branch_id', 'attendance_date',
        'status', 'remarks', 'marked_by', 'sync_uuid', 'source', 'edited_reason',
    ];

    protected $casts = [
        'attendance_date' => 'date',
    ];
}
