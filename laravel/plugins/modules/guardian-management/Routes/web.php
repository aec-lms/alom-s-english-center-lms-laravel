<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\GuardianManagement\Http\Controllers\GuardianController;

Route::middleware(['web', 'installed', 'auth', 'plugin:guardian-management', 'permission:guardians.manage'])
    ->prefix('admin/guardians')
    ->name('plugins.guardians.')
    ->group(function (): void {
        Route::get('/', [GuardianController::class, 'index'])->name('index');
        Route::post('/', [GuardianController::class, 'store'])->name('store');
    });
