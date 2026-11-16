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


Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])->group(function (): void {
    Route::get('/my-learning/enrollments', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'my'])
        ->name('plugins.enrollments.my');
    Route::post('/courses/{course}/enroll', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'store'])
        ->name('plugins.enrollments.store');
});

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management', 'permission:enrollments.manage'])
    ->prefix('admin/enrollments')
    ->name('plugins.enrollments.')
    ->group(function (): void {
        Route::get('/', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'index'])->name('index');
        Route::patch('/{enrollment}/status', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'updateStatus'])->name('status');
    });
