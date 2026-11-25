<?php

declare(strict_types=1);

namespace Plugins\Modules\HrStaffManagement\Services;

use Carbon\CarbonImmutable;
use Plugins\Modules\HrStaffManagement\Models\StaffAttendanceRecord;
use Plugins\Modules\HrStaffManagement\Models\StaffProfile;

class AttendancePayrollCalculator
{
    public function calculate(StaffProfile $staff, string $month, float $lateDeductionPerDay): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();
        $rows = StaffAttendanceRecord::query()
            ->where('staff_profile_id', $staff->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get(['status', 'leave_paid']);

        $counts = [
            'present_days' => $rows->where('status', 'present')->count(),
            'late_days' => $rows->where('status', 'late')->count(),
            'absent_days' => $rows->where('status', 'absent')->count(),
            'paid_leave_days' => $rows->where('status', 'leave')->where('leave_paid', true)->count(),
            'unpaid_leave_days' => $rows->where('status', 'leave')->where('leave_paid', false)->count(),
        ];

        $gross = round((float) $staff->base_salary, 2);
        $dailyRate = $start->daysInMonth > 0 ? $gross / $start->daysInMonth : 0;
        $attendanceDeduction = round(($counts['absent_days'] + $counts['unpaid_leave_days']) * $dailyRate, 2);
        $lateDeduction = round($counts['late_days'] * max(0, $lateDeductionPerDay), 2);

        return $counts + [
            'gross_salary' => $gross,
            'attendance_deduction' => $attendanceDeduction,
            'late_deduction' => $lateDeduction,
            'deductions' => round($attendanceDeduction + $lateDeduction, 2),
            'net_before_adjustments' => max(0, round($gross - $attendanceDeduction - $lateDeduction, 2)),
            'calendar_days' => $start->daysInMonth,
        ];
    }
}
