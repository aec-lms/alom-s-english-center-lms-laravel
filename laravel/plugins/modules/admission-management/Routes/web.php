<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\AdmissionManagement\Http\Controllers\AdmissionController;

Route::middleware(['web', 'installed', 'plugin:admission-management', 'throttle:5,1'])
    ->post('/admission/apply', [AdmissionController::class, 'applyPublic'])
    ->name('plugins.admissions.public_apply');

Route::middleware(['web', 'installed', 'plugin:admission-management'])
    ->get('/admission/track', [AdmissionController::class, 'trackForm'])
    ->name('plugins.admissions.track');

Route::middleware(['web', 'installed', 'plugin:admission-management', 'throttle:10,1'])
    ->post('/admission/track', [AdmissionController::class, 'trackPublic'])
    ->name('plugins.admissions.track_submit');

Route::middleware(['web', 'installed', 'auth', 'plugin:admission-management', 'permission:admissions.manage'])
    ->prefix('admin/admissions')
    ->name('plugins.admissions.')
    ->group(function (): void {
        Route::get('/', [AdmissionController::class, 'index'])->name('index');
        Route::post('/', [AdmissionController::class, 'store'])->name('store');
        Route::post('/{application}/review', [AdmissionController::class, 'review'])->name('review');
        Route::post('/{application}/documents', [AdmissionController::class, 'storeDocument'])->name('documents.store');
        Route::get('/{application}/documents/{document}', [AdmissionController::class, 'downloadDocument'])->name('documents.download');
        Route::post('/{application}/convert-to-student', [AdmissionController::class, 'convertToStudent'])->name('convert_to_student');
    });
