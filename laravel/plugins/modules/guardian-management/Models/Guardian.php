<?php

declare(strict_types=1);

namespace Plugins\Modules\GuardianManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Plugins\Modules\StudentManagement\Models\Student;

class Guardian extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'relationship', 'occupation', 'emergency_phone'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}
