<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\LtiPlatform\Http\Controllers\LtiPlatformController;

Route::middleware(['web', 'installed', 'plugin:lti-platform'])->group(function (): void {
    Route::get('/lti/platform/jwks', [LtiPlatformController::class, 'jwks'])->name('plugins.lti-platform.jwks');
    Route::post('/lti/platform/token', [LtiPlatformController::class, 'token'])
        ->middleware('throttle:30,1')
        ->name('plugins.lti-platform.token');

    Route::middleware('auth')->group(function (): void {
        Route::get('/lti/oidc/login', [LtiPlatformController::class, 'login'])->middleware('throttle:30,1')->name('plugins.lti-platform.login');
        Route::get('/admin/lti-tools', [LtiPlatformController::class, 'index'])->middleware('permission:lti.manage')->name('plugins.lti-platform.admin.index');
        Route::post('/admin/lti-tools', [LtiPlatformController::class, 'store'])->middleware('permission:lti.manage')->name('plugins.lti-platform.admin.store');
        Route::post('/admin/lti-tools/{tool}/toggle', [LtiPlatformController::class, 'toggle'])->whereNumber('tool')->middleware('permission:lti.manage')->name('plugins.lti-platform.admin.toggle');
    });
});
