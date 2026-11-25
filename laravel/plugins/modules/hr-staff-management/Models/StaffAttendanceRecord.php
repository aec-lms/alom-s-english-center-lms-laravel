<?php

declare(strict_types=1);

namespace Plugins\Modules\HrStaffManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceRecord extends Model
{
    protected $table = 'staff_attendance_records';

    protected $fillable = [
        'staff_profile_id', 'branch_id', 'attendance_date', 'status',
        'check_in_at', 'check_out_at', 'source', 'remarks', 'marked_by', 'leave_paid',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'leave_paid' => 'boolean',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}
