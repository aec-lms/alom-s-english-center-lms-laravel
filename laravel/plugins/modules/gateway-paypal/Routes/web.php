<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Modules\GatewayPaypal\Http\Controllers\PaypalController;

Route::middleware(['web', 'installed', 'plugin:gateway-paypal'])->group(function (): void {
    Route::get('/admin/payments/paypal', [PaypalController::class, 'index'])
        ->middleware(['auth', 'permission:payments.paypal.manage'])->name('plugins.gateway_paypal.index');
    Route::post('/payments/paypal/create-order', [PaypalController::class, 'createOrder'])
        ->middleware('auth')->name('plugins.gateway_paypal.create_order');
    Route::get('/payments/paypal/return', [PaypalController::class, 'captureReturn'])
        ->middleware('auth')->name('plugins.gateway_paypal.return');
    Route::get('/payments/paypal/cancel', [PaypalController::class, 'cancelReturn'])
        ->middleware('auth')->name('plugins.gateway_paypal.cancel');
    Route::post('/payments/paypal/webhook', [PaypalController::class, 'webhook'])
        ->withoutMiddleware([VerifyCsrfToken::class])->name('plugins.gateway_paypal.webhook');
});
