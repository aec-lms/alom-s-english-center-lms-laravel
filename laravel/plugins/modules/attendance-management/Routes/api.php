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
