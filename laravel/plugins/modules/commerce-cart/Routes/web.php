<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CommerceCart\Http\Controllers\CommerceCartController;

Route::middleware(['web', 'installed', 'auth', 'plugin:commerce-cart'])
    ->prefix('cart')
    ->name('plugins.commerce_cart.')
    ->group(function (): void {
        Route::get('/', [CommerceCartController::class, 'index'])->name('index');
        Route::post('/checkout', [CommerceCartController::class, 'checkout'])->middleware('throttle:5,1')->name('checkout');
        Route::post('/items', [CommerceCartController::class, 'add'])->middleware('throttle:30,1')->name('add');
        Route::patch('/items/{item}', [CommerceCartController::class, 'update'])->name('update');
        Route::delete('/items/{item}', [CommerceCartController::class, 'remove'])->name('remove');
    });
