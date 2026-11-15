<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\GatewayCard\Http\Controllers\CardCheckoutController;

Route::middleware(['web', 'installed', 'plugin:gateway-card'])
    ->prefix('payments/card')
    ->name('plugins.gateway.card.')
    ->group(function (): void {
        Route::post('/checkout', [CardCheckoutController::class, 'checkout'])
            ->middleware(['auth', 'permission:gateway.card.use'])
            ->name('checkout');
        Route::post('/webhook', [CardCheckoutController::class, 'webhook'])
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
            ->name('webhook');
    });
