<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\UniversalPayment\Http\Controllers\UniversalPaymentController;

Route::middleware(['web', 'installed', 'plugin:universal-payment'])
    ->post('/universal-checkout', [UniversalPaymentController::class, 'publicCheckout'])
    ->name('plugins.universal_payment.checkout');

Route::middleware(['web', 'installed', 'auth', 'plugin:universal-payment', 'permission:payments.universal.manage'])
    ->prefix('plugins/universal-payment')
    ->name('plugins.universal_payment.')
    ->group(function (): void {
        Route::get('/', [UniversalPaymentController::class, 'index'])->name('index');
        Route::post('/gateways', [UniversalPaymentController::class, 'saveGateways'])->name('gateways');
        Route::post('/transactions', [UniversalPaymentController::class, 'storeTransaction'])->name('store');
        Route::post('/transactions/{transaction}/status', [UniversalPaymentController::class, 'updateStatus'])->name('status');
    });
