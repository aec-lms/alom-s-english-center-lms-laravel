<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MobileAppApi\Http\Controllers\MobileAppConfigController;

Route::middleware(['web', 'installed', 'auth', 'plugin:mobile-app-api', 'permission:mobile.api.access'])
    ->prefix('admin/mobile-app')
    ->name('plugins.mobile_app.')
    ->group(function (): void {
        Route::get('/', [MobileAppConfigController::class, 'index'])->name('index');
        Route::post('/', [MobileAppConfigController::class, 'save'])->name('save');
    });
