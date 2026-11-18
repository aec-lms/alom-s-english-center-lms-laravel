<?php

declare(strict_types=1);

namespace Plugins\Modules\LearnAndEarn\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearnEarnQuestion extends Model
{
    protected $table = 'learn_earn_questions';

    protected $fillable = [
        'category_id', 'created_by', 'subject', 'difficulty', 'question',
        'options', 'correct_option_index', 'positive_marks', 'negative_marks',
        'explanation', 'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_option_index' => 'integer',
        'positive_marks' => 'decimal:2',
        'negative_marks' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(LearnEarnCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
