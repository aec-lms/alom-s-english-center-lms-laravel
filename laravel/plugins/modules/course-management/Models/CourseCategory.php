<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'color', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
