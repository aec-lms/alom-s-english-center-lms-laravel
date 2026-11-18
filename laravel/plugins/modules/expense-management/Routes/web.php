<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\ExpenseManagement\Http\Controllers\ExpenseController;

Route::middleware(['web', 'installed', 'auth', 'plugin:expense-management'])
    ->prefix('admin/expenses')
    ->name('plugins.expenses.')
    ->group(function (): void {
        Route::middleware('permission:expenses.view')->group(function (): void {
            Route::get('/', [ExpenseController::class, 'index'])->name('index');
            Route::get('/finance-summary', [ExpenseController::class, 'financeSummary'])->name('finance');
        });
        Route::middleware('permission:expenses.manage')->group(function (): void {
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
            Route::put('/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('update');
            Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('destroy');
        });
        Route::middleware('permission:expenses.approve')->group(function (): void {
            Route::post('/{expense}/review', [ExpenseController::class, 'review'])->whereNumber('expense')->name('review');
        });
        Route::middleware('permission:expenses.export')->get('/export.csv', [ExpenseController::class, 'export'])->name('export');
        Route::get('/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->whereNumber('expense')->name('receipt');
        Route::post('/recycle-bin/{expense}/restore', [ExpenseController::class, 'restore'])->whereNumber('expense')->middleware('permission:expenses.manage')->name('restore');
    });
