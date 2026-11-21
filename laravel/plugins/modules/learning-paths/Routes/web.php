<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\LearningPaths\Http\Controllers\LearningPathController;

Route::middleware(['web', 'installed', 'plugin:learning-paths'])->group(function (): void {
    Route::get('/learning-paths', [LearningPathController::class, 'catalog'])->name('learning-paths.catalog');
    Route::get('/learning-paths/{slug}', [LearningPathController::class, 'show'])->name('learning-paths.show');

    Route::middleware('auth')->group(function (): void {
        Route::get('/my-learning-paths', [LearningPathController::class, 'mine'])->name('learning-paths.mine');
        Route::post('/learning-paths/{slug}/enroll', [LearningPathController::class, 'enroll'])
            ->middleware('throttle:10,1')->name('learning-paths.enroll');
    });

    Route::middleware(['auth', 'permission:learning_paths.manage'])
        ->prefix('admin/learning-paths')
        ->name('plugins.learning-paths.admin.')
        ->group(function (): void {
            Route::get('/', [LearningPathController::class, 'index'])->name('index');
            Route::post('/', [LearningPathController::class, 'store'])->name('store');
            Route::put('/{path}', [LearningPathController::class, 'update'])->whereNumber('path')->name('update');
            Route::patch('/{path}/status', [LearningPathController::class, 'status'])->whereNumber('path')->name('status');
        });
});
