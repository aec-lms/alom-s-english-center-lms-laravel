<?php

declare(strict_types=1);

namespace Plugins\Modules\QuizContest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizContest extends Model
{
    protected $fillable = [
        'created_by', 'branch_id', 'title', 'description', 'prize_description',
        'winner_count', 'starts_at', 'ends_at', 'status', 'drawn_at',
    ];

    protected $casts = [
        'winner_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'drawn_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizContestQuestion::class)->orderBy('position');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(QuizContestEntry::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(QuizContestWinner::class)->orderBy('rank');
    }
}
