<?php

declare(strict_types=1);

namespace Plugins\Modules\FeesManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Modules\StudentManagement\Models\Student;

class FeeCategory extends Model
{
    protected $fillable = ['name', 'default_amount', 'is_active'];

    protected $casts = ['default_amount' => 'decimal:2', 'is_active' => 'boolean'];
}
