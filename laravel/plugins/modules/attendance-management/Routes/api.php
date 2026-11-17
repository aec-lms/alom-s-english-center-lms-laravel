<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceSyncController;

Route::middleware(['api', 'installed', 'plugin:attendance-management', 'auth:sanctum', 'permission:attendance.manage'])
    ->prefix('api/attendance')
    ->name('plugins.attendance.api.')
    ->group(function (): void {
        Route::post('/sync', [AttendanceSyncController::class, 'sync'])->name('sync');
    });
