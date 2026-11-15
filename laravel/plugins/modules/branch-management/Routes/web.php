<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\BranchManagement\Http\Controllers\BranchController;

Route::middleware(['web', 'installed', 'auth', 'plugin:branch-management', 'permission:branches.manage'])
    ->prefix('admin/branches')
    ->name('plugins.branches.')
    ->group(function (): void {
        Route::get('/', [BranchController::class, 'index'])->name('index');
        Route::post('/', [BranchController::class, 'store'])->name('store');
        Route::put('/{branch}', [BranchController::class, 'update'])->name('update');
        Route::post('/{branch}/toggle', [BranchController::class, 'toggle'])->name('toggle');
        Route::delete('/{branch}', [BranchController::class, 'destroy'])->name('destroy');
    });
