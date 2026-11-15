<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AdmissionManagement\Http\Controllers\AdmissionController;

Route::middleware(['web', 'installed', 'plugin:admission-management'])
    ->post('/admission/apply', [AdmissionController::class, 'applyPublic'])
    ->name('plugins.admissions.public_apply');

Route::middleware(['web', 'installed', 'auth', 'plugin:admission-management', 'permission:admissions.manage'])
    ->prefix('admin/admissions')
    ->name('plugins.admissions.')
    ->group(function (): void {
        Route::get('/', [AdmissionController::class, 'index'])->name('index');
        Route::post('/', [AdmissionController::class, 'store'])->name('store');
        Route::post('/{application}/review', [AdmissionController::class, 'review'])->name('review');
    });
