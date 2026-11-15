<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\ClassManagement\Http\Controllers\ClassController;

Route::middleware(['web', 'installed', 'auth', 'plugin:class-management', 'permission:classes.manage'])
    ->prefix('admin/classes')
    ->name('plugins.classes.')
    ->group(function (): void {
        Route::get('/', [ClassController::class, 'index'])->name('index');
        Route::post('/', [ClassController::class, 'store'])->name('store');
        Route::delete('/{academicClass}', [ClassController::class, 'destroy'])->name('destroy');
    });
