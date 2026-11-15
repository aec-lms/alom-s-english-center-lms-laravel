<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseManagement\Http\Controllers\CourseController;

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management', 'permission:courses.manage'])
    ->prefix('admin/courses')
    ->name('plugins.courses.')
    ->group(function (): void {
        Route::get('/', [CourseController::class, 'index'])->name('index');
        Route::post('/', [CourseController::class, 'store'])->name('store');
        Route::put('/{course}', [CourseController::class, 'update'])->name('update');
        Route::delete('/{course}', [CourseController::class, 'destroy'])->name('destroy');

        // Categories CRUD
        Route::post('/categories', [CourseController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [CourseController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [CourseController::class, 'destroyCategory'])->name('categories.destroy');

        // Live Classes CRUD
        Route::post('/live-classes', [CourseController::class, 'storeLiveClass'])->name('live.store');
        Route::put('/live-classes/{liveClass}', [CourseController::class, 'updateLiveClass'])->name('live.update');
        Route::delete('/live-classes/{liveClass}', [CourseController::class, 'destroyLiveClass'])->name('live.destroy');
    });
