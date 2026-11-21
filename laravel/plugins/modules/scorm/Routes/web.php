<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use Plugins\\Modules\\Scorm\\Http\\Controllers\\ScormPackageController;

Route::middleware(['web', 'installed', 'plugin:scorm'])->group(function (): void {
    Route::prefix('admin/scorm')->name('plugins.scorm.admin.')
        ->middleware(['auth', 'permission:scorm.manage'])
        ->group(function (): void {
            Route::get('/', [ScormPackageController::class, 'index'])->name('index');
            Route::post('/', [ScormPackageController::class, 'store'])->middleware('throttle:5,1')->name('store');
            Route::patch('/{package}/publish', [ScormPackageController::class, 'publish'])->whereNumber('package')->name('publish');
            Route::patch('/{package}/archive', [ScormPackageController::class, 'archive'])->whereNumber('package')->name('archive');
        });
});
