<?php

declare(strict_types=1);

namespace Plugins\\Modules\\QuestionBank\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

class QuestionPaper extends Model
{
    protected $table = 'question_bank_papers';

    protected $fillable = [
        'exam_category', 'title', 'instructions', 'duration_minutes', 'total_marks',
        'selection_mode', 'selection_criteria', 'snapshot_version', 'branch_id', 'created_by',
    ];

    protected $casts = ['selection_criteria' => 'array', 'total_marks' => 'decimal:2'];

    public function items(): HasMany
    {
        return $this->hasMany(QuestionPaperItem::class, 'paper_id')->orderBy('position');
    }
}
