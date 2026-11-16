<?php

declare(strict_types=1);

namespace Plugins\Modules\FeesManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Modules\StudentManagement\Models\Student;

class FeeInvoice extends Model
{
    protected $fillable = [
        'invoice_number', 'student_id', 'payer_name', 'payer_email', 'branch_id', 'fee_category_id',
        'subtotal_amount', 'discount_amount', 'net_amount', 'paid_amount',
        'currency', 'status', 'due_date',
    ];

    protected $casts = [
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }
}
