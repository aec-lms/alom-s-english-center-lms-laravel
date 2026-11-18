<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use Plugins\\Modules\\ExpenseManagement\\Http\\Controllers\\ExpenseController;

Route::middleware(['web', 'installed', 'auth', 'plugin:expense-management', 'permission:expenses.manage'])
    ->prefix('admin/expenses')
    ->name('plugins.expenses.')
    ->group(function (): void {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::put('/{expense}', [ExpenseController::class, 'update'])->name('update');
        Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->name('destroy');
    });
