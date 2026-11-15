<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\StudentManagement\Http\Controllers\StudentController;

Route::middleware(['web', 'installed', 'auth', 'plugin:student-management', 'permission:students.manage'])
    ->prefix('admin/students')
    ->name('plugins.students.')
    ->group(function (): void {
        Route::get('/', [StudentController::class, 'index'])->name('index');
        Route::post('/', [StudentController::class, 'store'])->name('store');
        Route::put('/{student}', [StudentController::class, 'update'])->name('update');
        Route::delete('/{student}', [StudentController::class, 'destroy'])->name('destroy');
    });
