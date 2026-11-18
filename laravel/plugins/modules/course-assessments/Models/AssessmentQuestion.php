<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentQuestion extends Model
{
    protected $table = 'course_assessment_questions';
    protected $fillable = ['assessment_set_id','source_question_id','type','question','options','correct_option','correct_answer','explanation','difficulty','tags','marks','position'];
    protected $casts = ['options'=>'array','tags'=>'array','correct_option'=>'integer','marks'=>'decimal:2','position'=>'integer'];
}
