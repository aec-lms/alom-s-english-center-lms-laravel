<?php

declare(strict_types=1);

namespace Plugins\\Modules\\QuestionBank\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\SoftDeletes;

class Question extends Model
{
    use SoftDeletes;

    protected $table = 'question_bank_questions';

    protected $fillable = [
        'exam_category', 'question_type', 'subject', 'topic', 'difficulty', 'prompt',
        'options', 'correct_answer', 'explanation', 'default_marks', 'default_negative_marks',
        'tags', 'branch_id', 'created_by', 'is_active',
    ];

    protected $casts = [
        'options' => 'array', 'tags' => 'array', 'is_active' => 'boolean',
        'default_marks' => 'decimal:2', 'default_negative_marks' => 'decimal:2',
    ];
}
