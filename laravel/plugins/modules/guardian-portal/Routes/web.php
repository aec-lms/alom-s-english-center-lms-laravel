<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\GuardianPortal\Http\Controllers\GuardianPortalController;

Route::middleware(['web', 'installed', 'auth', 'plugin:guardian-portal'])
    ->prefix('guardian-portal')
    ->name('plugins.guardian-portal.')
    ->group(function (): void {
        Route::get('/', [GuardianPortalController::class, 'index'])->name('dashboard');
    });
