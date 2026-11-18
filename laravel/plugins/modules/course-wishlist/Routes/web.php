<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseWishlist\Http\Controllers\CourseWishlistController;

Route::middleware(['web', 'installed', 'auth', 'plugin:course-wishlist'])
    ->prefix('my-learning/wishlist')
    ->name('plugins.course-wishlist.')
    ->group(function (): void {
        Route::get('/', [CourseWishlistController::class, 'index'])->name('index');
        Route::post('/{course}', [CourseWishlistController::class, 'store'])
            ->whereNumber('course')
            ->middleware('throttle:20,1')
            ->name('store');
        Route::delete('/{course}', [CourseWishlistController::class, 'destroy'])
            ->whereNumber('course')
            ->middleware('throttle:20,1')
            ->name('destroy');
    });
