<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentSet extends Model
{
    protected $table = 'course_assessment_sets';

    protected $fillable = ['course_id','branch_id','title','description','status','time_limit_minutes','max_attempts','questions_per_attempt','randomize_questions','available_from','available_until','created_by'];

    protected $casts = ['randomize_questions'=>'boolean','available_from'=>'datetime','available_until'=>'datetime'];

    public function questions(): HasMany { return $this->hasMany(AssessmentQuestion::class, 'assessment_set_id')->orderBy('position'); }
    public function attempts(): HasMany { return $this->hasMany(AssessmentAttempt::class, 'assessment_set_id'); }
}
