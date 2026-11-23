<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Plugins\Modules\UniversalPayment\Http\Controllers\UniversalPaymentController;

Route::middleware(['web', 'installed', 'auth', 'plugin:universal-payment'])
    ->post('/universal-checkout', [UniversalPaymentController::class, 'publicCheckout'])->middleware('throttle:10,1')
    ->name('plugins.universal_payment.checkout');

Route::middleware(['web', 'installed', 'plugin:universal-payment'])
    ->get('/universal-payment/bkash/callback', [UniversalPaymentController::class, 'bkashCallback'])
    ->name('plugins.universal_payment.bkash.callback');

Route::middleware(['web', 'installed', 'plugin:universal-payment'])
    ->prefix('universal-payment/sslcommerz')
    ->name('plugins.universal_payment.sslcommerz.')
    ->group(function (): void {
        Route::post('/success', [UniversalPaymentController::class, 'sslcommerzSuccess'])
            ->withoutMiddleware([VerifyCsrfToken::class])->name('success');
        Route::post('/failed', [UniversalPaymentController::class, 'sslcommerzFailed'])
            ->withoutMiddleware([VerifyCsrfToken::class])->name('failed');
        Route::post('/cancelled', [UniversalPaymentController::class, 'sslcommerzCancelled'])
            ->withoutMiddleware([VerifyCsrfToken::class])->name('cancelled');
        Route::post('/ipn', [UniversalPaymentController::class, 'sslcommerzIpn'])
            ->withoutMiddleware([VerifyCsrfToken::class])->name('ipn');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:universal-payment', 'permission:payments.universal.manage'])
    ->prefix('plugins/universal-payment')
    ->name('plugins.universal_payment.')
    ->group(function (): void {
        Route::get('/', [UniversalPaymentController::class, 'index'])->name('index');
        Route::post('/gateways', [UniversalPaymentController::class, 'saveGateways'])->name('gateways');
        Route::post('/transactions', [UniversalPaymentController::class, 'storeTransaction'])->name('store');
        Route::post('/transactions/{transaction}/status', [UniversalPaymentController::class, 'updateStatus'])->name('status');
        Route::get('/transactions/{transaction}/proof', [UniversalPaymentController::class, 'downloadProof'])->name('proof');
    });
