<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseBundles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseBundle extends Model
{
    protected $fillable = ['branch_id', 'created_by', 'title', 'slug', 'description', 'price', 'status'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourseBundleItem::class, 'bundle_id')->orderBy('position');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CourseBundleOrder::class, 'bundle_id');
    }
}
