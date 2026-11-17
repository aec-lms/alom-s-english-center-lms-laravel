<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'batch_id', 'attendance_batch_mapping_id', 'student_id', 'branch_id', 'attendance_date',
        'status', 'remarks', 'marked_by', 'sync_uuid', 'source', 'edited_reason',
    ];

    public function batchIdentity(): BelongsTo
    {
        return $this->belongsTo(AttendanceBatchMapping::class, 'attendance_batch_mapping_id');
    }

    protected $casts = [
        'attendance_date' => 'date',
    ];
}
