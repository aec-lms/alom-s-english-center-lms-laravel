<?php

declare(strict_types=1);

namespace Plugins\Modules\HrStaffManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayroll extends Model
{
    protected $table = 'staff_payrolls';

    protected $fillable = [
        'staff_profile_id',
        'branch_id',
        'payroll_month',
        'gross_salary',
        'bonus',
        'deductions',
        'net_paid',
        'status',
        'paid_at',
        'payment_reference',
        'present_days', 'late_days', 'absent_days', 'paid_leave_days', 'unpaid_leave_days',
        'attendance_deduction', 'late_deduction', 'late_deduction_per_day',
    ];

    protected $casts = [
        'gross_salary' => 'decimal:2',
        'bonus' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'attendance_deduction' => 'decimal:2',
        'late_deduction' => 'decimal:2',
        'late_deduction_per_day' => 'decimal:2',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}
