<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use Plugins\\Modules\\BoardQuestionSolver\\Http\\Controllers\\BoardQuestionSolverController;

Route::middleware(['web', 'installed', 'auth', 'plugin:board-question-solver'])->group(function (): void {
    Route::prefix('student/board-question-solver')
        ->name('student.board-solver.')
        ->middleware('permission:board-solver.use')
        ->group(function (): void {
            Route::get('/', [BoardQuestionSolverController::class, 'index'])->name('index');
            Route::post('/solve', [BoardQuestionSolverController::class, 'solve'])->middleware('throttle:5,1')->name('solve');
            Route::get('/history', [BoardQuestionSolverController::class, 'history'])->name('history');
        });

    Route::prefix('admin/board-question-solver')
        ->name('admin.board-solver.')
        ->middleware('permission:board-solver.configure')
        ->group(function (): void {
            Route::get('/settings', [BoardQuestionSolverController::class, 'settings'])->name('settings');
            Route::put('/settings', [BoardQuestionSolverController::class, 'updateSettings'])->middleware('throttle:10,1')->name('settings.update');
            Route::get('/history', [BoardQuestionSolverController::class, 'adminHistory'])->name('history');
        });
});
