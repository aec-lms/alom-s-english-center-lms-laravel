<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Modules\GatewaySslcommerz\Http\Controllers\SslcommerzController;

Route::middleware(['web', 'installed', 'plugin:gateway-sslcommerz'])
    ->prefix('payments/sslcommerz')
    ->name('plugins.gateway.sslcommerz.')
    ->group(function (): void {
        Route::post('/initiate', [SslcommerzController::class, 'initiate'])
            ->middleware('auth')
            ->name('initiate');
        Route::post('/ipn', [SslcommerzController::class, 'ipn'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('ipn');
        Route::post('/success', [SslcommerzController::class, 'success'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('success');
        Route::post('/failed', [SslcommerzController::class, 'failed'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('failed');
        Route::post('/cancelled', [SslcommerzController::class, 'cancelled'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('cancelled');
    });
