<?php

declare(strict_types=1);

namespace Plugins\Modules\ExamManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAttempt extends Model
{
    protected $fillable = [
        'exam_id', 'user_id', 'started_at', 'submitted_answers', 'exam_snapshot', 'score_obtained',
        'total_marks', 'passed', 'submitted_at',
    ];

    protected $casts = [
        'submitted_answers' => 'array',
        'exam_snapshot' => 'array',
        'passed' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score_obtained' => 'decimal:2',
        'total_marks' => 'decimal:2',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
