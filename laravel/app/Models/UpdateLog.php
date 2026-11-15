<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UpdateLog extends Model
{
    protected $fillable = [
        'target_type',
        'target_slug',
        'from_version',
        'to_version',
        'package_checksum',
        'status',
        'backup_reference',
        'notes',
        'executed_by',
    ];
}
