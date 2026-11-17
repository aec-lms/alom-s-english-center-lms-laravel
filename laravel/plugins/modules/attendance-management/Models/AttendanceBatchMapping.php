<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceBatchMapping extends Model
{
    protected $table = 'attendance_batch_mappings';

    protected $fillable = ['source_type', 'source_id', 'branch_id', 'display_name'];

    public static function resolve(string $sourceType, int $sourceId, ?int $branchId, ?string $displayName): self
    {
        abort_unless(in_array($sourceType, ['course_batch', 'study_batch'], true), 422, 'Unsupported attendance batch source.');

        return static::query()->firstOrCreate(
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            ['branch_id' => $branchId, 'display_name' => $displayName],
        );
    }
}
