<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AssignmentManagement\Http\Controllers\AssignmentController;

Route::middleware(['web', 'installed', 'auth', 'plugin:assignment-management'])
    ->prefix('admin/assignments')
    ->name('plugins.assignments.')
    ->group(function (): void {
        Route::middleware('permission:assignments.manage')->group(function (): void {
            Route::get('/', [AssignmentController::class, 'index'])->name('index');
            Route::post('/', [AssignmentController::class, 'store'])->name('store');
        });
        Route::middleware('permission:assignments.grade')->group(function (): void {
            Route::post('/submissions/{submission}/grade', [AssignmentController::class, 'grade'])->whereNumber('submission')->name('grade');
            Route::get('/submissions/{submission}/attachment', [AssignmentController::class, 'downloadSubmission'])->whereNumber('submission')->name('attachment');
        });
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:assignment-management'])
    ->prefix('student/assignments')
    ->name('plugins.assignments.student.')
    ->group(function (): void {
        Route::get('/', [AssignmentController::class, 'studentIndex'])->name('index');
        Route::get('/{assignment}', [AssignmentController::class, 'show'])->whereNumber('assignment')->name('show');
        Route::post('/{assignment}/submit', [AssignmentController::class, 'submit'])->whereNumber('assignment')->name('submit');
        Route::get('/submissions/{submission}/attachment', [AssignmentController::class, 'downloadOwnSubmission'])->whereNumber('submission')->name('attachment');
    });
