<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\GmailMarketing\Http\Controllers\GmailController;

Route::middleware(['web', 'installed', 'auth', 'plugin:gmail-marketing', 'permission:communication.email.manage', 'central.admin'])
    ->prefix('plugins/gmail-marketing')
    ->name('plugins.gmail.')
    ->group(function (): void {
        Route::get('/', [GmailController::class, 'index'])->name('index');
        Route::post('/settings', [GmailController::class, 'saveSettings'])->name('settings');
        Route::post('/campaigns', [GmailController::class, 'sendCampaign'])->name('send');
        Route::delete('/campaigns/{campaign}', [GmailController::class, 'destroy'])->name('destroy');
    });
