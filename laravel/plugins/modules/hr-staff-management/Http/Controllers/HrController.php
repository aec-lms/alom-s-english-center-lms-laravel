<?php

declare(strict_types=1);

namespace Plugins\Modules\HrStaffManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\HrStaffManagement\Models\StaffPayroll;
use Plugins\Modules\HrStaffManagement\Models\StaffProfile;

class HrController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $staff = Schema::hasTable('staff_profiles')
            ? StaffProfile::query()
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderBy('full_name')
                ->get()
            : collect();

        $payrolls = Schema::hasTable('staff_payrolls')
            ? StaffPayroll::query()
                ->with('staff')
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->orderByDesc('id')
                ->limit(40)
                ->get()
            : collect();

        return view('hr-staff-management::index', compact('staff', 'payrolls'));
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'employee_code' => ['required', 'string', 'max:40', 'unique:staff_profiles,employee_code'],
            'full_name' => ['required', 'string', 'max:160'],
            'designation' => ['required', 'string', 'max:120'],
            'department' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,on_leave,inactive'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $member = StaffProfile::query()->create($validated + [
            'status' => $validated['status'] ?? 'active',
            'joined_at' => now()->toDateString(),
        ]);

        $this->audit->log('hr.staff_created', 'staff_profile', (string) $member->id);

        return back()->with('status', "Staff member '{$member->full_name}' added.");
    }

    public function recordPayroll(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'payroll_month' => ['required', 'string', 'max:16'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
        ]);

        $member = StaffProfile::query()->findOrFail($validated['staff_profile_id']);
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null && (int) $member->branch_id !== (int) $actor->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $gross = (float) $member->base_salary;
        $bonus = (float) ($validated['bonus'] ?? 0);
        $deductions = (float) ($validated['deductions'] ?? 0);
        $netPaid = max(0.0, round($gross + $bonus - $deductions, 2));

        $payroll = StaffPayroll::query()->create([
            'staff_profile_id' => $member->id,
            'branch_id' => $member->branch_id,
            'payroll_month' => $validated['payroll_month'],
            'gross_salary' => $gross,
            'bonus' => $bonus,
            'deductions' => $deductions,
            'net_paid' => $netPaid,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->audit->log('hr.payroll_recorded', 'staff_payroll', (string) $payroll->id, ['net_paid' => $netPaid]);

        return back()->with('status', "Payroll disbursed for {$member->full_name} ({$validated['payroll_month']}).");
    }
}
