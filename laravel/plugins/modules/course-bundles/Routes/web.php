<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CourseBundles\Http\Controllers\CourseBundleController;

Route::middleware(['web', 'installed', 'plugin:course-bundles'])->group(function (): void {
    Route::get('/course-bundles', [CourseBundleController::class, 'publicIndex'])->name('course-bundles.index');
    Route::get('/course-bundles/{slug}', [CourseBundleController::class, 'show'])->name('course-bundles.show');

    Route::middleware('auth')->group(function (): void {
        Route::post('/course-bundles/{slug}/checkout', [CourseBundleController::class, 'checkout'])->name('course-bundles.checkout');
        Route::get('/my-bundle-orders', [CourseBundleController::class, 'myOrders'])->name('course-bundles.orders');
        Route::get('/my-bundle-orders/{order}', [CourseBundleController::class, 'orderShow'])->whereNumber('order')->name('course-bundles.orders.show');
    });

    Route::middleware(['auth', 'permission:course_bundles.manage'])
        ->prefix('admin/course-bundles')
        ->name('plugins.course-bundles.')
        ->group(function (): void {
            Route::get('/', [CourseBundleController::class, 'index'])->name('index');
            Route::post('/', [CourseBundleController::class, 'store'])->name('store');
            Route::put('/{bundle}', [CourseBundleController::class, 'update'])->whereNumber('bundle')->name('update');
            Route::post('/{bundle}/archive', [CourseBundleController::class, 'archive'])->whereNumber('bundle')->name('archive');
        });
});
