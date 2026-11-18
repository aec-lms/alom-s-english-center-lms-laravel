<?php

declare(strict_types=1);

namespace Plugins\\Modules\\BoardQuestionSolver\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use App\\Models\\User;

class BoardQuestionSolution extends Model
{
    protected $fillable = [
        'user_id', 'board', 'exam_year', 'class_level', 'subject',
        'question_type', 'mode', 'language', 'question', 'solution',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
