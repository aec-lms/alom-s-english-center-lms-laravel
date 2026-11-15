<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\TeacherManagement\Http\Controllers\TeacherController;

Route::middleware(['web', 'installed', 'auth', 'plugin:teacher-management', 'permission:teachers.manage'])
    ->prefix('admin/teachers')
    ->name('plugins.teachers.')
    ->group(function (): void {
        Route::get('/', [TeacherController::class, 'index'])->name('index');
        Route::post('/', [TeacherController::class, 'store'])->name('store');
        Route::put('/{teacher}', [TeacherController::class, 'update'])->name('update');
        Route::delete('/{teacher}', [TeacherController::class, 'destroy'])->name('destroy');
    });
