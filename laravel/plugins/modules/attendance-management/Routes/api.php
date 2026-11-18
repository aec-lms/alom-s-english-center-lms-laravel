<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceSyncController;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceQrController;
use Plugins\Modules\AttendanceManagement\Http\Controllers\InstructorAttendanceSyncController;

Route::middleware(['api', 'installed', 'plugin:attendance-management', 'auth:sanctum', 'permission:attendance.manage'])
    ->prefix('api/attendance')
    ->name('plugins.attendance.api.')
    ->group(function (): void {
        Route::post('/sync', [AttendanceSyncController::class, 'sync'])->name('sync');
    });


// Native student clients submit the same short-lived QR token through Sanctum.
// Keep the web check-in route for browser users; both paths share one controller
// so expiry, replay, roster, branch and consent rules cannot drift.
Route::middleware(['api', 'installed', 'plugin:attendance-management', 'auth:sanctum'])
    ->prefix('api/v1/attendance')
    ->name('plugins.attendance.mobile.')
    ->group(function (): void {
        Route::post('/qr/check-in', [AttendanceQrController::class, 'checkin'])
            ->middleware('throttle:10,1')
            ->name('qr.checkin');
    });


Route::middleware(['api', 'installed', 'plugin:attendance-management', 'auth:sanctum'])
    ->prefix('api/v1/instructor/attendance')
    ->name('plugins.attendance.instructor.')
    ->group(function (): void {
        Route::post('/sync', [InstructorAttendanceSyncController::class, 'sync'])
            ->middleware('throttle:20,1')
            ->name('sync');
        Route::patch('/{attendance}/resolve', [InstructorAttendanceSyncController::class, 'resolve'])
            ->whereNumber('attendance')
            ->middleware('throttle:20,1')
            ->name('resolve');
    });
