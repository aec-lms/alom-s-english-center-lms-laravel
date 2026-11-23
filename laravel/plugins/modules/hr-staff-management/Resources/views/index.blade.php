@extends('layouts.admin')
@section('title', 'HR & Staff Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">HR, Staff &amp; Payroll Management</h1>
        <p class="text-xs text-slate-500 mt-0.5">Manage academic &amp; operational staff profiles, designations, and monthly payroll disbursements.</p>
    </div>


    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <div><h2 class="text-sm font-bold text-slate-900">Staff Daily Attendance</h2><p class="text-xs text-slate-500">One attendance entry per staff member per day. Check-in/out timestamps are optional.</p></div>
        <form method="POST" action="{{ route('plugins.hr.attendance.store') }}" class="grid md:grid-cols-6 gap-3 text-xs">
            @csrf
            <select name="staff_profile_id" required class="md:col-span-2 rounded-lg border border-slate-300 px-3 py-2 bg-white"><option value="">Select staff member...</option>@foreach ($staff as $member)<option value="{{ $member->id }}">{{ $member->full_name }} ({{ $member->employee_code }})</option>@endforeach</select>
            <input type="date" name="attendance_date" required max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" class="rounded-lg border border-slate-300 px-3 py-2">
            <select name="status" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="leave">Leave</option></select>\n            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" name="leave_paid" value="1" checked> Paid leave</label>
            <input type="datetime-local" name="check_in_at" aria-label="Check in" class="rounded-lg border border-slate-300 px-3 py-2">
            <input type="datetime-local" name="check_out_at" aria-label="Check out" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="remarks" maxlength="255" placeholder="Remarks (optional)" class="md:col-span-4 rounded-lg border border-slate-300 px-3 py-2">
            <button type="submit" class="md:col-span-2 px-4 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Save Attendance</button>
        </form>
        <div class="space-y-2">@forelse ($attendanceRecords as $record)<div class="flex items-center justify-between border-b border-slate-100 py-2 text-xs"><span class="font-semibold text-slate-800">{{ $record->staff?->full_name ?? 'Staff #'.$record->staff_profile_id }}</span><span class="text-slate-500">{{ $record->attendance_date?->format('Y-m-d') }} · {{ ucfirst($record->status) }}{{ $record->status === 'leave' ? ($record->leave_paid ? ' (paid)' : ' (unpaid)') : '' }} · {{ $record->check_in_at?->format('H:i') ?? '—' }} / {{ $record->check_out_at?->format('H:i') ?? '—' }}</span></div>@empty<p class="text-slate-400 text-xs">No staff attendance recorded yet.</p>@endforelse</div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Register Staff Member</h2>
            <form method="POST" action="{{ route('plugins.hr.staff.store') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="employee_code" required placeholder="Employee Code (e.g. EMP-01) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="full_name" required placeholder="Full Name *" class="rounded-lg border border-slate-300 px-3 py-2">
                <div class="grid grid-cols-2 gap-3">
                    <input name="designation" required placeholder="Designation *" class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="department" required value="Academic" class="rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <input name="base_salary" type="number" step="0.01" min="0" required placeholder="Base Salary (BDT) *" class="rounded-lg border border-slate-300 px-3 py-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Add Staff</button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Disburse Monthly Payroll</h2>
            <form method="POST" action="{{ route('plugins.hr.payrolls.store') }}" class="grid gap-3 text-xs">
                @csrf
                <select name="staff_profile_id" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="">Select Staff Member...</option>
                    @foreach ($staff as $member)
                        <option value="{{ $member->id }}">{{ $member->full_name }} ({{ $member->employee_code }} — ৳{{ $member->base_salary }})</option>
                    @endforeach
                </select>
                <input name="payroll_month" required value="{{ now()->format('Y-m') }}" placeholder="YYYY-MM" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <div class="grid grid-cols-2 gap-3">
                    <input name="bonus" type="number" step="0.01" min="0" value="0" placeholder="Bonus" class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="deductions" type="number" step="0.01" min="0" value="0" placeholder="Additional deductions" class="rounded-lg border border-slate-300 px-3 py-2">\n                    <input name="late_deduction_per_day" type="number" step="0.01" min="0" value="0" placeholder="Late penalty per day" class="rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white font-bold">Record Payroll</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Recent Payroll Runs ({{ $payrolls->count() }})</h2>
        @forelse ($payrolls as $pay)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $pay->staff?->full_name ?? 'Staff #' . $pay->staff_profile_id }}</div>
                    <div class="text-slate-500 font-mono">Month {{ $pay->payroll_month }} &middot; Gross ৳{{ $pay->gross_salary }} + Bonus ৳{{ $pay->bonus }} &minus; Ded ৳{{ $pay->deductions }}</div>
                </div>
                <div class="text-right">
                    <div class="font-extrabold text-emerald-700 text-sm">৳{{ $pay->net_paid }}</div>
                    <div class="text-[11px] uppercase text-slate-500">{{ $pay->status }}</div>
                    @if ($pay->status !== 'paid')
                        <form method="POST" action="{{ route('plugins.hr.payrolls.mark_paid', $pay) }}" class="mt-2 flex flex-col gap-2">
                            @csrf
                            <input name="payment_reference" required maxlength="120" placeholder="Bank / cash reference" class="rounded border border-slate-300 px-2 py-1 text-xs">
                            <button type="submit" class="rounded bg-emerald-600 px-3 py-1 text-xs font-bold text-white">Confirm Paid</button>
                        </form>
                    @else
                        <div class="mt-1 text-[10px] text-slate-500">{{ $pay->paid_at?->format('Y-m-d H:i') }} · {{ $pay->payment_reference }}</div>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-slate-400">No payroll runs recorded yet.</p>
        @endforelse
    </div>
</div>
@endsection
