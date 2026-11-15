<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'batch_id', 'student_id', 'branch_id', 'attendance_date',
        'status', 'remarks', 'marked_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
    ];
}
