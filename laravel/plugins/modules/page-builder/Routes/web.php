<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\PageBuilder\Http\Controllers\PageBuilderController;\nuse Plugins\Modules\PageBuilder\Http\Controllers\ReusableBlockController;

Route::middleware(['web', 'installed', 'auth', 'plugin:page-builder', 'permission:page_builder.manage', 'central.admin'])
    ->prefix('admin/page-builder')
    ->name('plugins.page_builder.')
    ->group(function (): void {
        Route::get('/', [PageBuilderController::class, 'index'])->name('index');\n        Route::get('/reusable-blocks', [ReusableBlockController::class, 'index'])->name('reusable.index');\n        Route::post('/reusable-blocks', [ReusableBlockController::class, 'store'])->name('reusable.store');\n        Route::delete('/reusable-blocks/{id}', [ReusableBlockController::class, 'destroy'])->name('reusable.destroy');
        Route::post('/', [PageBuilderController::class, 'store'])->name('store');
        Route::put('/{page}', [PageBuilderController::class, 'update'])->name('update');
        Route::post('/{page}/revisions/{revision}/restore', [PageBuilderController::class, 'restoreRevision'])->name('revisions.restore');
        Route::delete('/{page}', [PageBuilderController::class, 'destroy'])->name('destroy');
    });
