<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\FeesManagement\Http\Controllers\FeeController;

Route::middleware(['web', 'installed', 'auth', 'plugin:fees-management', 'permission:fees.manage'])
    ->prefix('admin/fees')
    ->name('plugins.fees.')
    ->group(function (): void {
        Route::get('/', [FeeController::class, 'index'])->name('index');
        Route::post('/invoices', [FeeController::class, 'storeInvoice'])->name('invoices.store');
        Route::post('/invoices/{invoice}/waive', [FeeController::class, 'waiveInvoice'])->whereNumber('invoice')->name('invoices.waive');
        Route::post('/invoices/{invoice}/remind', [FeeController::class, 'sendDueReminder'])->whereNumber('invoice')->middleware('throttle:3,10')->name('invoices.remind');
    });
