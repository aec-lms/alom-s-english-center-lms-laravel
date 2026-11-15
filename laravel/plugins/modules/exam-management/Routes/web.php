<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\ExamManagement\Http\Controllers\ExamController;

Route::middleware(['web', 'installed', 'auth', 'plugin:exam-management'])
    ->prefix('admin/exams')
    ->name('plugins.exams.')
    ->group(function (): void {
        Route::get('/', [ExamController::class, 'index'])
            ->middleware('permission:exams.manage')
            ->name('index');
        Route::post('/', [ExamController::class, 'store'])
            ->middleware('permission:exams.manage')
            ->name('store');
        Route::put('/{exam}', [ExamController::class, 'update'])
            ->middleware('permission:exams.manage')
            ->name('update');
        Route::delete('/{exam}', [ExamController::class, 'destroy'])
            ->middleware('permission:exams.manage')
            ->name('destroy');
        Route::post('/{exam}/questions', [ExamController::class, 'addQuestion'])
            ->middleware('permission:exams.manage')
            ->name('questions.store');
        Route::post('/{exam}/attempt', [ExamController::class, 'submitAttempt'])
            ->middleware('permission:exams.attempt')
            ->name('attempt');
    });
