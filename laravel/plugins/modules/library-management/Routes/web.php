<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\LibraryManagement\Http\Controllers\LibraryController;

Route::middleware(['web', 'installed', 'auth', 'plugin:library-management', 'permission:library.manage'])
    ->prefix('admin/library')
    ->name('plugins.library.')
    ->group(function (): void {
        Route::get('/', [LibraryController::class, 'index'])->name('index');
        Route::post('/books', [LibraryController::class, 'storeBook'])->name('books.store');
        Route::post('/loans', [LibraryController::class, 'issueLoan'])->name('loans.issue');
        Route::post('/loans/{loan}/return', [LibraryController::class, 'returnLoan'])->name('loans.return');
    });
