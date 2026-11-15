<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CertificateManagement\Http\Controllers\CertificateController;

Route::middleware(['web', 'installed', 'auth', 'plugin:certificate-management', 'permission:certificates.manage'])
    ->prefix('admin/certificates')
    ->name('plugins.certificates.')
    ->group(function (): void {
        Route::get('/', [CertificateController::class, 'index'])->name('index');
        Route::post('/', [CertificateController::class, 'store'])->name('store');
        Route::post('/{certificate}/revoke', [CertificateController::class, 'revoke'])->name('revoke');
    });
