<?php

declare(strict_types=1);

namespace Plugins\Modules\TeacherManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'branch_id',
        'employee_code',
        'specialization',
        'photo_path',
        'credentials',
        'bio',
        'youtube_url',
        'facebook_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deleted_user_was_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::restored(function (Teacher $teacher): void {
            if (!Schema::hasColumn('teachers', 'deleted_user_was_active') || !$teacher->user) {
                return;
            }

            // Restore the login account to the exact active state it had before
            // the teacher profile was moved to the Recycle Bin.
            $teacher->user->update([
                'is_active' => (bool) $teacher->deleted_user_was_active,
            ]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
