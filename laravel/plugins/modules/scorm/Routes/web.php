<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\Scorm\Http\Controllers\ScormPackageController;
use Plugins\Modules\Scorm\Http\Controllers\ScormRuntimeController;

Route::middleware(['web', 'installed', 'plugin:scorm'])->group(function (): void {
    Route::get('/my-learning/scorm', [ScormRuntimeController::class, 'studentIndex'])
        ->middleware('auth')->name('plugins.scorm.student.index');
    Route::get('/my-learning/scorm/{package}', [ScormRuntimeController::class, 'launch'])
        ->whereNumber('package')->middleware('auth')->name('plugins.scorm.launch');

    Route::prefix('admin/scorm')->name('plugins.scorm.admin.')
        ->middleware(['auth', 'permission:scorm.manage'])
        ->group(function (): void {
            Route::get('/', [ScormPackageController::class, 'index'])->name('index');
            Route::post('/', [ScormPackageController::class, 'store'])->middleware('throttle:5,1')->name('store');
            Route::patch('/{package}/publish', [ScormPackageController::class, 'publish'])->whereNumber('package')->name('publish');
            Route::patch('/{package}/archive', [ScormPackageController::class, 'archive'])->whereNumber('package')->name('archive');
        });

    // Resolve the paths on every install, including test runtimes that override
    // the configured origin after Laravel has booted. The controller enforces
    // the exact host, while signed:relative binds the short-lived player URL to
    // this path/query without accidentally signing APP_URL instead.
    Route::get('/scorm-player/{attempt}/{token}', [ScormRuntimeController::class, 'contentPlayer'])
        ->whereNumber('attempt')->middleware('signed:relative')->name('plugins.scorm.content.player');

    Route::get('/scorm-bootstrap/{attempt}/{token}/{path?}', [ScormRuntimeController::class, 'contentBootstrap'])
        ->whereNumber('attempt')->where('path', '.*')->name('plugins.scorm.content.bootstrap');

    Route::get('/scorm-content/{attempt}/{path?}', [ScormRuntimeController::class, 'contentFile'])
        ->whereNumber('attempt')->where('path', '.*')->name('plugins.scorm.content.file');
});
