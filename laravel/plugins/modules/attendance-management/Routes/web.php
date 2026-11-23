<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceController;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceAuditController;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceQrController;
use Plugins\Modules\AttendanceManagement\Http\Controllers\AttendanceSyncController;

Route::middleware(['web', 'installed', 'auth', 'plugin:attendance-management', 'permission:attendance.manage'])
    ->prefix('admin/attendance')
    ->name('plugins.attendance.')
    ->group(function (): void {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');\n        Route::get('/teachers', [TeacherAttendanceController::class, 'index'])->name('teachers.index');\n        Route::post('/teachers', [TeacherAttendanceController::class, 'store'])->name('teachers.store');
        Route::get('/audit', [AttendanceAuditController::class, 'index'])->name('audit');
        Route::get('/audit/export.csv', [AttendanceAuditController::class, 'export'])->name('audit.export');
        Route::post('/', [AttendanceController::class, 'store'])->name('store');
        Route::post('/sync', [AttendanceSyncController::class, 'sync'])->name('sync');
        Route::put('/batches/{mappingId}/roster', [AttendanceQrController::class, 'assignRoster'])->whereNumber('mappingId')->name('roster.update');
        Route::post('/batches/{mappingId}/qr', [AttendanceQrController::class, 'issue'])->whereNumber('mappingId')->name('qr.issue');
        Route::delete('/qr-sessions/{sessionId}', [AttendanceQrController::class, 'revoke'])->whereNumber('sessionId')->name('qr.revoke');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:attendance-management'])
    ->prefix('attendance')
    ->name('plugins.attendance.')
    ->group(function (): void {
        Route::get('/qr/check-in', [AttendanceQrController::class, 'showCheckin'])->name('qr.checkin.page');
        Route::post('/qr/check-in', [AttendanceQrController::class, 'checkin'])->name('qr.checkin');
        Route::delete('/gps-consent', [AttendanceQrController::class, 'revokeGpsConsent'])->name('gps-consent.revoke');
        Route::post('/teacher/punch', [TeacherAttendanceController::class, 'punch'])->name('teacher.punch');\n        Route::get('/my-history', [AttendanceQrController::class, 'studentHistory'])->name('student.history');
        Route::get('/guardian/{studentId}', [AttendanceQrController::class, 'guardianHistory'])->whereNumber('studentId')->name('guardian.history');
    });
