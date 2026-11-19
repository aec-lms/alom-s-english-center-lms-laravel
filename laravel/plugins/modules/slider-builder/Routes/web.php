<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\SliderBuilder\Http\Controllers\SliderBuilderController;

Route::middleware(['web', 'installed', 'auth', 'plugin:slider-builder', 'permission:slider_builder.manage', 'central.admin'])
    ->prefix('admin/slider-builder')
    ->name('plugins.slider_builder.')
    ->group(function (): void {
        Route::get('/', [SliderBuilderController::class, 'index'])->name('index');
        Route::post('/', [SliderBuilderController::class, 'store'])->name('store');
        Route::patch('/reorder', [SliderBuilderController::class, 'reorder'])->name('reorder');
        Route::put('/{slider}', [SliderBuilderController::class, 'update'])->name('update');
        Route::delete('/{slider}', [SliderBuilderController::class, 'destroy'])->name('destroy');
    });
