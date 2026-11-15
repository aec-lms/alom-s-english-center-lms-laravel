<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\GatewaySslcommerz\Http\Controllers\SslcommerzController;

Route::middleware(['web', 'installed', 'plugin:gateway-sslcommerz'])
    ->prefix('payments/sslcommerz')
    ->name('plugins.gateway.sslcommerz.')
    ->group(function (): void {
        Route::post('/initiate', [SslcommerzController::class, 'initiate'])
            ->middleware(['auth', 'permission:gateway.sslcommerz.use'])
            ->name('initiate');
        Route::post('/ipn', [SslcommerzController::class, 'ipn'])
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
            ->name('ipn');
    });
