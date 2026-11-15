<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'branch_id',
        'category_id',
        'cms_post_id',
        'cms_category_id',
        'code',
        'title',
        'category',
        'instructor_name',
        'slug',
        'description',
        'syllabus_json',
        'thumbnail',
        'pdf_path',
        'pdf_thumbnail',
        'seo_title',
        'seo_description',
        'price',
        'original_price',
        'duration_weeks',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'syllabus_json' => 'array',
        ];
    }
}
