<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceController;

Route::middleware(['web', 'installed', 'auth', 'plugin:attendance-management'])
    ->prefix('admin/attendance')->name('plugins.attendance.')
    ->group(function (): void {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('/offline', [AttendanceController::class, 'offline'])->name('offline');
        Route::get('/batches/{batch}/roster', [AttendanceController::class, 'roster'])->name('roster');
        Route::post('/batches/{batch}/students', [AttendanceController::class, 'assignStudent'])->name('assign');
        Route::post('/sync', [AttendanceController::class, 'syncOffline'])->name('sync');
        Route::post('/qr/session', [AttendanceController::class, 'issueQr'])->name('qr.issue');
        Route::patch('/records/{record}', [AttendanceController::class, 'update'])->name('update');
        Route::get('/records/{record}/history', [AttendanceController::class, 'history'])->name('history');
        Route::post('/', [AttendanceController::class, 'store'])->name('store');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:attendance-management'])
    ->get('/my-attendance', [AttendanceController::class, 'portalAttendance'])
    ->name('plugins.attendance.portal');
