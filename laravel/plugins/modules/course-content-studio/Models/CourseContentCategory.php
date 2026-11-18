<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CourseContentCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(CourseContentPost::class, 'course_content_post_category', 'category_id', 'course_content_post_id');
    }
}
