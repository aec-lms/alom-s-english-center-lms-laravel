<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\PageBuilder\Http\Controllers\PageBuilderController;

Route::middleware(['web', 'installed', 'auth', 'plugin:page-builder', 'permission:page_builder.manage'])
    ->prefix('admin/page-builder')
    ->name('plugins.page_builder.')
    ->group(function (): void {
        Route::get('/', [PageBuilderController::class, 'index'])->name('index');
        Route::post('/', [PageBuilderController::class, 'store'])->name('store');
        Route::put('/{page}', [PageBuilderController::class, 'update'])->name('update');
        Route::delete('/{page}', [PageBuilderController::class, 'destroy'])->name('destroy');
    });
