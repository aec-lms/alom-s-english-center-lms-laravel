<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseCurriculumItem extends Model
{
    protected $fillable = ['course_id','course_content_post_id','section_title','position','is_preview','is_active','duration_minutes'];

    protected function casts(): array { return ['is_preview'=>'boolean','is_active'=>'boolean']; }

    public function post(): BelongsTo { return $this->belongsTo(CourseContentPost::class, 'course_content_post_id'); }
    public function progress(): HasMany { return $this->hasMany(CourseContentProgress::class, 'course_curriculum_item_id'); }
}
