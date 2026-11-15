<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'student_code', 'admission_date', 'status'];

    protected function casts(): array
    {
        return ['admission_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
