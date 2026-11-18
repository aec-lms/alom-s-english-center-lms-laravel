<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AssignmentManagement\Http\Controllers\AssignmentController;

Route::middleware(['api', 'installed', 'plugin:assignment-management', 'auth:sanctum'])
    ->prefix('api/v1/my/assignments')
    ->name('plugins.assignments.api.')
    ->group(function (): void {
        Route::get('/', [AssignmentController::class, 'apiIndex'])->name('index');
        Route::get('/{assignment}', [AssignmentController::class, 'apiShow'])
            ->whereNumber('assignment')
            ->name('show');
        Route::post('/{assignment}/submit', [AssignmentController::class, 'apiSubmit'])
            ->whereNumber('assignment')
            ->middleware('throttle:10,1')
            ->name('submit');
        Route::get('/submissions/{submission}/attachment', [AssignmentController::class, 'downloadOwnSubmission'])
            ->whereNumber('submission')
            ->name('attachment');
    });
