<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\HrStaffManagement\Http\Controllers\HrController;

Route::middleware(['web', 'installed', 'auth', 'plugin:hr-staff-management', 'permission:hr.manage'])
    ->prefix('admin/hr')
    ->name('plugins.hr.')
    ->group(function (): void {
        Route::get('/', [HrController::class, 'index'])->name('index');
        Route::post('/staff', [HrController::class, 'storeStaff'])->name('staff.store');
        Route::post('/attendance', [HrController::class, 'recordAttendance'])->name('attendance.store');
        Route::post('/payrolls', [HrController::class, 'recordPayroll'])->name('payrolls.store');
        Route::post('/payrolls/{payroll}/mark-paid', [HrController::class, 'markPayrollPaid'])->name('payrolls.mark_paid');
    });
