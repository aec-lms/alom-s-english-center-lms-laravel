<?php

declare(strict_types=1);

namespace Plugins\Modules\QuizContest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizContestWinner extends Model
{
    protected $fillable = [
        'quiz_contest_id', 'quiz_contest_entry_id', 'user_id', 'rank',
        'prize_snapshot', 'drawn_by', 'drawn_at',
    ];

    protected $casts = ['rank' => 'integer', 'drawn_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(QuizContestEntry::class, 'quiz_contest_entry_id');
    }
}
