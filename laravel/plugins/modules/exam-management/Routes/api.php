<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\ExamManagement\Http\Controllers\ExamController;

Route::middleware(['api', 'installed', 'plugin:exam-management', 'auth:sanctum'])
    ->prefix('api/v1/my/exams')
    ->name('plugins.exams.api.')
    ->group(function (): void {
        Route::get('/', [ExamController::class, 'apiIndex'])->name('index');
        Route::post('/{exam}/attempts', [ExamController::class, 'apiTake'])
            ->whereNumber('exam')
            ->middleware('throttle:10,1')
            ->name('attempts.start');
        Route::post('/{exam}/attempts/{attempt}/submit', [ExamController::class, 'apiSubmitAttempt'])
            ->whereNumber('exam')
            ->whereNumber('attempt')
            ->middleware('throttle:5,1')
            ->name('attempts.submit');
    });
