<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseAssessments\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentAttemptQuestion extends Model
{
    protected $table = 'course_assessment_attempt_questions';
    protected $fillable = ['attempt_id','source_question_id','type','question','options','correct_option','correct_answer','explanation','marks','position','selected_option','answer_text','is_correct','marks_awarded','manual_feedback','graded_by','graded_at'];
    protected $casts = ['options'=>'array','correct_option'=>'integer','selected_option'=>'integer','is_correct'=>'boolean','marks'=>'decimal:2','marks_awarded'=>'decimal:2','graded_at'=>'datetime'];
}
