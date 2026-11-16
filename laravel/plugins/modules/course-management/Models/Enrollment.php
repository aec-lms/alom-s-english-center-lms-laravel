<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;

class Enrollment extends Model
{
    protected $fillable = ['user_id', 'course_id', 'branch_id', 'invoice_id', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'invoice_id');
    }
}
