<?php

declare(strict_types=1);

namespace Plugins\Modules\HrStaffManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Plugins\Modules\HrStaffManagement\Models\StaffPayroll;
use Plugins\Modules\HrStaffManagement\Models\StaffProfile;
use Plugins\Modules\HrStaffManagement\Models\StaffAttendanceRecord;
use Plugins\Modules\HrStaffManagement\Services\AttendancePayrollCalculator;

class HrController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $staff = Schema::hasTable('staff_profiles')
            ? StaffProfile::query()
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderBy('full_name')
                ->get()
            : collect();

        $attendanceRecords = Schema::hasTable('staff_attendance_records')
            ? StaffAttendanceRecord::query()->with('staff')
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderByDesc('attendance_date')->limit(40)->get()
            : collect();

        $payrolls = Schema::hasTable('staff_payrolls')
            ? StaffPayroll::query()
                ->with('staff')
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderByDesc('id')
                ->limit(40)
                ->get()
            : collect();

        return view('hr-staff-management::index', compact('staff', 'payrolls', 'attendanceRecords'));
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'employee_code' => ['required', 'string', 'max:40', 'unique:staff_profiles,employee_code'],
            'full_name' => ['required', 'string', 'max:160'],
            'designation' => ['required', 'string', 'max:120'],
            'department' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,on_leave,inactive'],
            'branch_id' => $this->branchRule(),
        ]);

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        if (!$this->branchIntegrationAvailable()) { $validated['branch_id'] = null; }

        $member = StaffProfile::query()->create($validated + [
            'status' => $validated['status'] ?? 'active',
            'joined_at' => now()->toDateString(),
        ]);

        $this->audit->log('hr.staff_created', 'staff_profile', (string) $member->id);

        return back()->with('status', "Staff member '{$member->full_name}' added.");
    }

    public function exportAttendance(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        abort_unless(Schema::hasTable('staff_attendance_records'), 409, 'Staff attendance is not installed.');
        $filters = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $start = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $filters['month'])->startOfMonth();
        $end = $start->endOfMonth();
        $query = DB::table('staff_attendance_records')
            ->join('staff_profiles', 'staff_profiles.id', '=', 'staff_attendance_records.staff_profile_id')
            ->leftJoin('users as marker', 'marker.id', '=', 'staff_attendance_records.marked_by')
            ->whereBetween('staff_attendance_records.attendance_date', [$start->toDateString(), $end->toDateString()])
            ->when($actor && $actor->hasRole('branch_manager'), fn ($q) => $q->where('staff_attendance_records.branch_id', $actor->branch_id))
            ->select([
                'staff_attendance_records.attendance_date',
                'staff_profiles.employee_code',
                'staff_profiles.full_name',
                'staff_profiles.department',
                'staff_profiles.designation',
                'staff_attendance_records.status',
                'staff_attendance_records.leave_paid',
                'staff_attendance_records.check_in_at',
                'staff_attendance_records.check_out_at',
                'staff_attendance_records.remarks',
                'marker.name as marked_by',
            ]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee Code', 'Staff Name', 'Department', 'Designation', 'Status', 'Leave Paid', 'Check In', 'Check Out', 'Remarks', 'Marked By']);
            $query->orderBy('staff_attendance_records.attendance_date')->chunk(500, function ($items) use ($out): void {
                foreach ($items as $item) {
                    $safe = static fn ($value): string => preg_match('/^[=+@\\\\-\\t\\r]/', (string) $value) ? "'".(string) $value : (string) $value;
                    fputcsv($out, [
                        $item->attendance_date, $safe($item->employee_code), $safe($item->full_name),
                        $safe($item->department), $safe($item->designation), $item->status,
                        $item->status === 'leave' ? ($item->leave_paid ? 'Paid' : 'Unpaid') : '',
                        $item->check_in_at, $item->check_out_at, $safe($item->remarks), $safe($item->marked_by),
                    ]);
                }
            });
            fclose($out);
        }, 'staff-attendance-'.$filters['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function recordAttendance(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        abort_unless(Schema::hasTable('staff_attendance_records'), 409, 'Staff attendance is not installed.');
        $validated = $request->validate([
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'attendance_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', 'in:present,absent,late,leave'],
            'leave_paid' => ['nullable', 'boolean'],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $record = DB::transaction(function () use ($validated, $actor) {
            $member = StaffProfile::query()->whereKey($validated['staff_profile_id'])->lockForUpdate()->firstOrFail();
            if ($actor && $actor->hasRole('branch_manager') && (int) $member->branch_id !== (int) $actor->branch_id) abort(403, 'Branch isolation violation.');
            $record = StaffAttendanceRecord::query()->where('staff_profile_id', $member->id)->whereDate('attendance_date', $validated['attendance_date'])->lockForUpdate()->first() ?? new StaffAttendanceRecord();
            $validated['leave_paid'] = $validated['status'] === 'leave' ? (bool) ($validated['leave_paid'] ?? true) : true;
            $record->fill($validated + ['branch_id' => $member->branch_id, 'source' => 'admin', 'marked_by' => $actor?->id]);
            $record->save();
            return $record;
        });
        $this->audit->log('hr.staff_attendance_recorded', 'staff_attendance', (string) $record->id, [
            'staff_profile_id' => $record->staff_profile_id,
            'attendance_date' => $record->attendance_date?->toDateString(),
            'status' => $record->status,
        ]);
        return back()->with('status', 'Staff attendance saved.');
    }

    public function recordPayroll(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $validated = $request->validate([
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'payroll_month' => ['required', 'regex:/^\\d{4}-(0[1-9]|1[0-2])$/'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
            'late_deduction_per_day' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        abort_unless(Schema::hasTable('staff_attendance_records'), 409, 'Staff attendance must be installed before attendance-based payroll.');
        $payroll = DB::transaction(function () use ($validated, $actor) {
            $member = StaffProfile::query()->whereKey($validated['staff_profile_id'])->lockForUpdate()->firstOrFail();
            if ($actor && $actor->hasRole('branch_manager') && (int) $member->branch_id !== (int) $actor->branch_id) {
                abort(403, 'Branch isolation violation.');
            }
            $duplicate = StaffPayroll::query()->where('staff_profile_id', $member->id)
                ->where('payroll_month', $validated['payroll_month'])->lockForUpdate()->exists();
            abort_if($duplicate, 409, 'Payroll already exists for this staff member and month.');

            $calculation = app(AttendancePayrollCalculator::class)->calculate(
                $member,
                $validated['payroll_month'],
                (float) ($validated['late_deduction_per_day'] ?? 0),
            );
            $bonus = round((float) ($validated['bonus'] ?? 0), 2);
            $manualDeduction = round((float) ($validated['deductions'] ?? 0), 2);
            $totalDeductions = round($calculation['deductions'] + $manualDeduction, 2);
            $netPaid = max(0, round($calculation['gross_salary'] + $bonus - $totalDeductions, 2));

            return StaffPayroll::query()->create([
                'staff_profile_id' => $member->id,
                'branch_id' => $member->branch_id,
                'payroll_month' => $validated['payroll_month'],
                'gross_salary' => $calculation['gross_salary'],
                'bonus' => $bonus,
                'deductions' => $totalDeductions,
                'net_paid' => $netPaid,
                'present_days' => $calculation['present_days'],
                'late_days' => $calculation['late_days'],
                'absent_days' => $calculation['absent_days'],
                'paid_leave_days' => $calculation['paid_leave_days'],
                'unpaid_leave_days' => $calculation['unpaid_leave_days'],
                'attendance_deduction' => $calculation['attendance_deduction'],
                'late_deduction' => $calculation['late_deduction'],
                'late_deduction_per_day' => (float) ($validated['late_deduction_per_day'] ?? 0),
                'status' => 'pending',
                'paid_at' => null,
                'payment_reference' => null,
            ]);
        });

        $this->audit->log('hr.payroll_recorded', 'staff_payroll', (string) $payroll->id, [
            'net_paid' => $payroll->net_paid,
            'attendance_deduction' => $payroll->attendance_deduction,
            'late_deduction' => $payroll->late_deduction,
        ]);

        return back()->with('status', "Attendance-based payroll prepared for {$payroll->staff?->full_name} ({$validated['payroll_month']}). Confirm disbursement after payment.");
    }

    public function markPayrollPaid(Request $request, StaffPayroll $payroll): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('admin'), 403, 'Only a central administrator can confirm payroll disbursement.');
        $this->ensureAssignedBranch($actor);

        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $payroll->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        if ($payroll->status === 'paid') {
            abort(409, 'This payroll has already been marked as paid.');
        }

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:120'],
        ]);

        $payroll->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_reference' => trim($validated['payment_reference']),
        ]);

        $this->audit->log('hr.payroll_marked_paid', 'staff_payroll', (string) $payroll->id, [
            'payment_reference' => $payroll->payment_reference,
        ]);

        return back()->with('status', 'Payroll marked as paid with the supplied disbursement reference.');
    }

    private function branchIntegrationAvailable(): bool
    {
        return app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
    }

    private function branchRule(): array
    {
        return $this->branchIntegrationAvailable()
            ? ['nullable', 'integer', 'exists:branches,id']
            : ['nullable', 'integer'];
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !$this->branchIntegrationAvailable()) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
        $activeBranch = \Illuminate\Support\Facades\DB::table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
