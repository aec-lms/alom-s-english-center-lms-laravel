<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    protected $fillable = ['exam_id', 'prompt', 'options', 'correct_option_index', 'marks'];

    protected $casts = [
        'options' => 'array',
        'correct_option_index' => 'integer',
        'marks' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
