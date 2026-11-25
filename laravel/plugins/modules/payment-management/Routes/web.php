<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\PaymentManagement\Http\Controllers\PaymentController;

Route::middleware(['web', 'installed', 'auth', 'plugin:payment-management', 'permission:payments.manage'])
    ->prefix('admin/payments')
    ->name('plugins.payments.')
    ->group(function (): void {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::get('/collections/export.csv', [PaymentController::class, 'exportCollections'])->name('collections.export');
        Route::post('/offline', [PaymentController::class, 'recordOffline'])->name('offline');
        Route::post('/{transaction}/refund', [PaymentController::class, 'refund'])->name('refund');
    });
