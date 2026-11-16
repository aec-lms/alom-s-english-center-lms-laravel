<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceController;

Route::middleware(['api', 'installed', 'plugin:attendance-management', 'auth:sanctum'])
    ->prefix('api/mobile/attendance')->name('plugins.attendance.api.')
    ->group(function (): void {
        Route::post('/bulk', [AttendanceController::class, 'bulkStore'])->name('bulk');
        Route::get('/batches/{batch}/roster', [AttendanceController::class, 'roster'])->name('roster');
        Route::post('/batches/{batch}/students', [AttendanceController::class, 'assignStudent'])->name('assign');
        Route::post('/sync', [AttendanceController::class, 'syncOffline'])->name('sync');
        Route::post('/qr/session', [AttendanceController::class, 'issueQr'])->name('qr.issue');
        Route::post('/qr/mark', [AttendanceController::class, 'markQr'])->name('qr.mark');
        Route::get('/me', [AttendanceController::class, 'myAttendance'])->name('me');
        Route::get('/guardian/{student}', [AttendanceController::class, 'guardianAttendance'])->name('guardian');
        Route::get('/reports', [AttendanceController::class, 'report'])->name('reports');
        Route::patch('/{record}', [AttendanceController::class, 'update'])->name('update');
    });
