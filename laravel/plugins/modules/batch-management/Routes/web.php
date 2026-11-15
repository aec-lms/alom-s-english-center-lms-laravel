<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\BatchManagement\Http\Controllers\BatchController;

Route::middleware(['web', 'installed', 'auth', 'plugin:batch-management', 'permission:batches.manage'])
    ->prefix('admin/batches')
    ->name('plugins.batches.')
    ->group(function (): void {
        Route::get('/', [BatchController::class, 'index'])->name('index');
        Route::post('/', [BatchController::class, 'store'])->name('store');
        Route::delete('/{batch}', [BatchController::class, 'destroy'])->name('destroy');
    });
