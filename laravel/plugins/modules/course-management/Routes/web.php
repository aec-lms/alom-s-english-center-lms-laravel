<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseManagement\Http\Controllers\CourseController;
use Plugins\Modules\CourseManagement\Http\Controllers\CourseTeacherAssignmentController;
use Plugins\Modules\CourseManagement\Http\Controllers\LessonContentProtectionController;
use Plugins\Modules\CourseManagement\Http\Controllers\LiveClassAccessController;

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management', 'permission:courses.manage'])
    ->prefix('admin/courses')
    ->name('plugins.courses.')
    ->group(function (): void {
        Route::get('/', [CourseController::class, 'index'])->name('index');
        Route::get('/teacher-assignments', [CourseTeacherAssignmentController::class, 'index'])->name('teachers.index');
        Route::post('/teacher-assignments', [CourseTeacherAssignmentController::class, 'store'])->name('teachers.store');
        Route::delete('/teacher-assignments/{assignment}', [CourseTeacherAssignmentController::class, 'destroy'])->whereNumber('assignment')->name('teachers.destroy');
        Route::get('/content-protection', [LessonContentProtectionController::class, 'adminIndex'])->name('content-protection');
        Route::post('/', [CourseController::class, 'store'])->name('store');
        Route::put('/{course}', [CourseController::class, 'update'])->name('update');
        Route::delete('/{course}', [CourseController::class, 'destroy'])->name('destroy');

        Route::post('/lessons/{lesson}/media', [LessonContentProtectionController::class, 'upload'])->name('lessons.media.upload');
        Route::patch('/lessons/{lesson}/protection', [LessonContentProtectionController::class, 'updatePolicy'])->name('lessons.protection.update');

        // Categories CRUD
        Route::post('/categories', [CourseController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [CourseController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [CourseController::class, 'destroyCategory'])->name('categories.destroy');

        // Live Classes CRUD
        Route::post('/live-classes', [CourseController::class, 'storeLiveClass'])->name('live.store');
        Route::put('/live-classes/{liveClass}', [CourseController::class, 'updateLiveClass'])->name('live.update');
        Route::delete('/live-classes/{liveClass}', [CourseController::class, 'destroyLiveClass'])->name('live.destroy');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])
    ->prefix('live-classes/{liveClass}')
    ->name('plugins.live-classes.')
    ->group(function (): void {
        Route::post('/join', [LiveClassAccessController::class, 'join'])
            ->middleware('throttle:10,1')->name('join');
        Route::post('/heartbeat', [LiveClassAccessController::class, 'heartbeat'])
            ->middleware('throttle:6,1')->name('heartbeat');
        Route::post('/leave', [LiveClassAccessController::class, 'leave'])
            ->middleware('throttle:10,1')->name('leave');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])
    ->prefix('student/course-commerce')
    ->name('course-commerce.')
    ->group(function (): void {
        Route::get('/cart', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'cart'])->name('cart');
        Route::post('/cart/items/{course}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'add'])->whereNumber('course')->name('cart.add');
        Route::delete('/cart/items/{item}', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'remove'])->whereNumber('item')->name('cart.remove');
        Route::post('/checkout', [\Plugins\Modules\CourseManagement\Http\Controllers\CourseCommerceController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management'])->group(function (): void {
    Route::get('/my-learning/enrollments', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'my'])
        ->name('plugins.enrollments.my');
    Route::post('/courses/{course}/enroll', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'store'])
        ->name('plugins.enrollments.store');

    Route::get('/my-learning/lessons/{lesson}/media-access', [LessonContentProtectionController::class, 'issueAccess'])
        ->name('plugins.lesson-media.access')->middleware('throttle:30,1');
    Route::get('/my-learning/lessons/{lesson}/media/{user}', [LessonContentProtectionController::class, 'stream'])
        ->name('plugins.lesson-media.stream')->middleware(['signed', 'throttle:60,1']);
    Route::get('/my-learning/lessons/{lesson}/download/{user}', [LessonContentProtectionController::class, 'download'])
        ->name('plugins.lesson-media.download')->middleware(['signed', 'throttle:10,1']);
    Route::get('/my-learning/courses/{course}/lessons', [LessonContentProtectionController::class, 'showCourse'])
        ->name('plugins.lessons.course');
});

Route::middleware(['web', 'installed', 'auth', 'plugin:course-management', 'permission:enrollments.manage'])
    ->prefix('admin/enrollments')
    ->name('plugins.enrollments.')
    ->group(function (): void {
        Route::get('/', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'index'])->name('index');
        Route::patch('/{enrollment}/status', [\Plugins\Modules\CourseManagement\Http\Controllers\EnrollmentController::class, 'updateStatus'])->name('status');
    });
