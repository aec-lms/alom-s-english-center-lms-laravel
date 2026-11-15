<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CarouselBuilder\Http\Controllers\CarouselBuilderController;

Route::middleware(['web', 'installed', 'auth', 'plugin:carousel-builder', 'permission:carousel_builder.manage'])
    ->prefix('admin/carousel-builder')
    ->name('plugins.carousel_builder.')
    ->group(function (): void {
        Route::get('/', [CarouselBuilderController::class, 'index'])->name('index');
        Route::post('/', [CarouselBuilderController::class, 'store'])->name('store');
        Route::put('/{item}', [CarouselBuilderController::class, 'update'])->name('update');
        Route::delete('/{item}', [CarouselBuilderController::class, 'destroy'])->name('destroy');
    });
