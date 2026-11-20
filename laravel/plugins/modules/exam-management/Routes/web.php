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
        // Backward-compatible endpoint; the controller enforces the student role.
        Route::post('/{exam}/attempt', [ExamController::class, 'submitAttempt'])->name('attempt.admin');
        Route::post('/{exam}/questions', [ExamController::class, 'addQuestion'])
            ->middleware('permission:exams.manage')
            ->name('questions.store');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:exam-management'])
    ->group(function (): void {
        Route::get('/exams/{exam}/take', [ExamController::class, 'take'])
            ->name('plugins.exams.take')->middleware('throttle:20,1,exam-take:');
        Route::get('/exams/{exam}/attempts', [ExamController::class, 'history'])
            ->name('plugins.exams.history')->middleware('throttle:30,1,exam-history:');
        Route::post('/exams/{exam}/attempt', [ExamController::class, 'submitAttempt'])
            ->name('plugins.exams.attempt')->middleware('throttle:5,1,exam-submit:');
        Route::post('/exams/{exam}/attempt/autosave', [ExamController::class, 'saveAttemptAnswers'])
            ->name('plugins.exams.autosave')->middleware('throttle:30,1,exam-autosave:');
    });
