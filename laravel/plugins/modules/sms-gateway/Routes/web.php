<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\SmsGateway\Http\Controllers\SmsController;

Route::middleware(['web', 'installed', 'auth', 'plugin:sms-gateway', 'permission:communication.sms.manage'])
    ->prefix('plugins/sms')
    ->name('plugins.sms.')
    ->group(function (): void {
        Route::get('/', [SmsController::class, 'index'])->name('index');
        Route::post('/settings', [SmsController::class, 'saveSettings'])->name('settings');
        Route::post('/campaigns', [SmsController::class, 'sendCampaign'])->name('send');
        Route::delete('/campaigns/{campaign}', [SmsController::class, 'destroy'])->name('destroy');
    });
