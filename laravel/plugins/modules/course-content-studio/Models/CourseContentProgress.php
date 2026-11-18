<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseContentStudio\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseContentProgress extends Model
{
    protected $fillable = ['user_id','course_curriculum_item_id','last_viewed_at','completed_at'];
    protected function casts(): array { return ['last_viewed_at'=>'datetime','completed_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function item(): BelongsTo { return $this->belongsTo(CourseCurriculumItem::class, 'course_curriculum_item_id'); }
}
