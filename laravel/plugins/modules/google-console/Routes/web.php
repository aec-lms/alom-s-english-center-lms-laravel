<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\GoogleConsole\Http\Controllers\GoogleConsoleController;

Route::middleware(['web', 'installed', 'auth', 'plugin:google-console', 'permission:marketing.google_console.manage', 'central.admin'])
    ->prefix('plugins/google-console')
    ->name('plugins.google_console.')
    ->group(function (): void {
        Route::get('/', [GoogleConsoleController::class, 'index'])->name('index');
        Route::post('/', [GoogleConsoleController::class, 'save'])->name('save');
    });
