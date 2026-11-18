<?php

declare(strict_types=1);

namespace Plugins\\Modules\\CourseAssessments\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

class AssessmentAttempt extends Model
{
    protected $table = 'course_assessment_attempts';
    protected $fillable = ['assessment_set_id','course_id','student_id','attempt_number','status','started_at','deadline_at','submitted_at','auto_score','manual_score','max_score','final_score','graded_by','graded_at','teacher_feedback'];
    protected $casts = ['started_at'=>'datetime','deadline_at'=>'datetime','submitted_at'=>'datetime','graded_at'=>'datetime','auto_score'=>'decimal:2','manual_score'=>'decimal:2','max_score'=>'decimal:2','final_score'=>'decimal:2'];

    public function assessmentSet(): BelongsTo
    {
        return $this->belongsTo(AssessmentSet::class, 'assessment_set_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AssessmentAttemptQuestion::class, 'attempt_id')->orderBy('position');
    }
}
