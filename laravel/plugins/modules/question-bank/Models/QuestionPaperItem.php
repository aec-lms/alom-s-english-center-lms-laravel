<?php

declare(strict_types=1);

namespace Plugins\\Modules\\QuestionBank\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class QuestionPaperItem extends Model
{
    protected $table = 'question_bank_paper_items';

    protected $fillable = ['paper_id', 'question_id', 'position', 'question_snapshot', 'marks', 'negative_marks'];

    protected $casts = ['question_snapshot' => 'array', 'marks' => 'decimal:2', 'negative_marks' => 'decimal:2'];

    public function paper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class, 'paper_id');
    }
}
