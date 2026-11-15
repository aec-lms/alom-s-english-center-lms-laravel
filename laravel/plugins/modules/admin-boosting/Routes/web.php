<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AdminBoosting\Http\Controllers\BoostController;

Route::middleware(['web', 'installed', 'plugin:admin-boosting'])
    ->get('/boost/click/{campaign}', [BoostController::class, 'trackClick'])
    ->name('plugins.boost.click');

Route::middleware(['web', 'installed', 'auth', 'plugin:admin-boosting', 'permission:marketing.boost.manage'])
    ->prefix('plugins/boost')
    ->name('plugins.boost.')
    ->group(function (): void {
        Route::get('/', [BoostController::class, 'index'])->name('index');
        Route::post('/', [BoostController::class, 'store'])->name('store');
        Route::put('/{campaign}', [BoostController::class, 'update'])->name('update');
        Route::delete('/{campaign}', [BoostController::class, 'destroy'])->name('destroy');
    });
