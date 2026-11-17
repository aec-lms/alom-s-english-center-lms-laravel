<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\VisitorPopupAds\Http\Controllers\PopupController;

Route::middleware(['web', 'installed', 'plugin:visitor-popup-ads', 'throttle:60,1'])
    ->post('/popup-ads/{popup}/click', [PopupController::class, 'trackClick'])
    ->name('plugins.popups.click');

Route::middleware(['web', 'installed', 'auth', 'plugin:visitor-popup-ads', 'permission:marketing.popups.manage', 'central.admin'])
    ->prefix('plugins/visitor-popups')
    ->name('plugins.popups.')
    ->group(function (): void {
        Route::get('/', [PopupController::class, 'index'])->name('index');
        Route::post('/', [PopupController::class, 'store'])->name('store');
        Route::put('/{popup}', [PopupController::class, 'update'])->name('update');
        Route::delete('/{popup}', [PopupController::class, 'destroy'])->name('destroy');
    });
