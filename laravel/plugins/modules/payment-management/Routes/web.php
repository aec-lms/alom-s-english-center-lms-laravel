<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\PaymentManagement\Http\Controllers\PaymentController;

Route::middleware(['web', 'installed', 'auth', 'plugin:payment-management', 'permission:payments.manage'])
    ->prefix('admin/payments')
    ->name('plugins.payments.')
    ->group(function (): void {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::get('/refunds/export.csv', [PaymentController::class, 'exportRefundAudit'])->name('refunds.export');
        Route::post('/offline', [PaymentController::class, 'recordOffline'])->name('offline');
        Route::post('/{transaction}/refund', [PaymentController::class, 'refund'])->name('refund');
        Route::post('/refunds/{refund}/approve', [PaymentController::class, 'approveRefund'])->name('refunds.approve');
        Route::post('/refunds/{refund}/reject', [PaymentController::class, 'rejectRefund'])->name('refunds.reject');
        Route::post('/refunds/{refund}/reconcile', [PaymentController::class, 'reconcileRefund'])->name('refunds.reconcile');
    });
