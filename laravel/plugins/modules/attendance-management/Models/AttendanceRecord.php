<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'batch_id', 'student_id', 'branch_id', 'attendance_date', 'status',
        'remarks', 'marked_by', 'client_uuid', 'source', 'sync_status',
        'latitude', 'longitude', 'location_accuracy', 'location_captured_at',
        'updated_by', 'update_reason', 'qr_session_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'location_captured_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'location_accuracy' => 'decimal:2',
    ];
}
