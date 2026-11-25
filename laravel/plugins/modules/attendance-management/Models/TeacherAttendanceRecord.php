<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Modules\TeacherManagement\Models\Teacher;

class TeacherAttendanceRecord extends Model
{
    protected $table = 'teacher_attendance_records';
    protected $fillable = ['teacher_id', 'branch_id', 'attendance_date', 'status', 'check_in_at', 'check_out_at', 'source', 'remarks', 'marked_by'];
    protected $casts = ['attendance_date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime'];

    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class, 'teacher_id'); }
    public function marker(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'marked_by'); }
}
