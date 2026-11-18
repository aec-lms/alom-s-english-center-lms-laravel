<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\StudentTracking\Http\Controllers\StudentTrackingController;

Route::middleware(['web', 'installed', 'auth', 'plugin:student-tracking'])
    ->prefix('student-tracking')
    ->name('plugins.student-tracking.')
    ->group(function (): void {
        Route::get('/mine', [StudentTrackingController::class, 'mine'])->name('mine');
        Route::get('/guardian', [StudentTrackingController::class, 'guardian'])->name('guardian');
        Route::post('/events', [StudentTrackingController::class, 'record'])->middleware('throttle:60,1')->name('events.record');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:student-tracking', 'permission:student-tracking.view'])
    ->prefix('admin/student-tracking')
    ->name('plugins.student-tracking.admin.')
    ->group(function (): void {
        Route::get('/', [StudentTrackingController::class, 'index'])->name('index');
    });
