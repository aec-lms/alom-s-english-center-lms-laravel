<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\StorefrontCatalog\Http\Controllers\StorefrontController;


Route::middleware(['web', 'installed', 'auth', 'plugin:storefront-catalog', 'permission:cms.manage', 'central.admin'])
    ->prefix('admin/storefront')
    ->name('plugins.storefront.')
    ->group(function (): void {
        Route::get('/', [StorefrontController::class, 'index'])->name('index');
        Route::post('/', [StorefrontController::class, 'store'])->name('store');
        Route::put('/{item}', [StorefrontController::class, 'update'])->name('update');
        Route::delete('/{item}', [StorefrontController::class, 'destroy'])->name('destroy');
    });
