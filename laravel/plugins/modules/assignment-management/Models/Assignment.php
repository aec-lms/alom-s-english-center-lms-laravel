<?php

declare(strict_types=1);

namespace Plugins\Modules\AssignmentManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $table = 'assignment_tasks';

    protected $fillable = [
        'branch_id', 'course_id', 'created_by', 'title', 'instructions',
        'due_at', 'max_score', 'allow_late', 'status',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'max_score' => 'decimal:2', 'allow_late' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }
}
