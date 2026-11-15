<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceController;

Route::middleware(['web', 'installed', 'auth', 'plugin:attendance-management', 'permission:attendance.manage'])
    ->prefix('admin/attendance')
    ->name('plugins.attendance.')
    ->group(function (): void {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::post('/', [AttendanceController::class, 'store'])->name('store');
    });
