<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MetaPixel\Http\Controllers\MetaPixelController;

Route::middleware(['web', 'installed', 'auth', 'plugin:meta-pixel', 'permission:marketing.pixel.manage', 'central.admin'])
    ->prefix('plugins/meta-pixel')
    ->name('plugins.meta_pixel.')
    ->group(function (): void {
        Route::get('/', [MetaPixelController::class, 'index'])->name('index');
        Route::post('/', [MetaPixelController::class, 'save'])->name('save');
    });
