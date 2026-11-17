<?php

declare(strict_types=1);

namespace Plugins\\Modules\\AttendanceManagement\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class AttendanceBatchMapping extends Model
{
    protected $table = 'attendance_batch_mappings';

    protected $fillable = ['source_type', 'source_id', 'branch_id', 'display_name'];
}
