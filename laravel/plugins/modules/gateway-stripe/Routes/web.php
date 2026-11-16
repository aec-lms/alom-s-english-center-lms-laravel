<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Modules\GatewayStripe\Http\Controllers\StripeController;

Route::middleware(['web', 'installed', 'plugin:gateway-stripe'])->group(function (): void {
    Route::get('/admin/payments/stripe', [StripeController::class, 'index'])
        ->middleware(['auth', 'permission:payments.stripe.manage'])->name('plugins.gateway_stripe.index');
    Route::post('/payments/stripe/checkout', [StripeController::class, 'checkout'])
        ->middleware('auth')->name('plugins.gateway_stripe.checkout');
    Route::post('/payments/stripe/webhook', [StripeController::class, 'webhook'])
        ->withoutMiddleware([VerifyCsrfToken::class])->name('plugins.gateway_stripe.webhook');
});
