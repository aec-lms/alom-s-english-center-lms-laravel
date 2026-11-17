<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    protected $fillable = ['exam_id', 'prompt', 'options', 'correct_option_index', 'marks', 'negative_marks'];

    protected $casts = [
        'options' => 'array',
        'correct_option_index' => 'integer',
        'marks' => 'decimal:2',
        'negative_marks' => 'decimal:2',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
