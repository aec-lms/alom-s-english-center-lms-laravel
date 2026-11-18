<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseWishlist\Http\Controllers\CourseWishlistController;

Route::middleware(['api', 'installed', 'plugin:course-wishlist', 'auth:sanctum'])
    ->prefix('api/v1/my/wishlist')
    ->name('plugins.course-wishlist.api.')
    ->group(function (): void {
        Route::get('/', [CourseWishlistController::class, 'apiIndex'])->name('index');
        Route::post('/{course}', [CourseWishlistController::class, 'apiStore'])
            ->whereNumber('course')
            ->middleware('throttle:20,1')
            ->name('store');
        Route::delete('/{course}', [CourseWishlistController::class, 'apiDestroy'])
            ->whereNumber('course')
            ->middleware('throttle:20,1')
            ->name('destroy');
    });
