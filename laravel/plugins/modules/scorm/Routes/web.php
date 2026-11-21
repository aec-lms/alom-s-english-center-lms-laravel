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
});

$playerHost = parse_url((string) config('scorm.player_origin', ''), PHP_URL_HOST);
$contentHost = parse_url((string) config('scorm.content_origin', ''), PHP_URL_HOST);
$appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

if (is_string($playerHost) && $playerHost !== '' && $playerHost !== $appHost && $playerHost !== $contentHost) {
    Route::domain($playerHost)->middleware(['web', 'installed', 'plugin:scorm'])->group(function (): void {
        Route::get('/scorm-player/{attempt}/{token}', [ScormRuntimeController::class, 'contentPlayer'])
            ->whereNumber('attempt')->name('plugins.scorm.content.player')->middleware('signed');
    });
}

if (is_string($contentHost) && $contentHost !== '' && $contentHost !== $appHost && $contentHost !== $playerHost) {
    Route::domain($contentHost)->middleware(['web', 'installed', 'plugin:scorm'])->group(function (): void {
        // Token is accepted only by the bootstrap route. It is exchanged for an
        // HttpOnly, host-only cookie and then removed from the visible SCO URL.
        Route::get('/scorm-bootstrap/{attempt}/{token}/{path?}', [ScormRuntimeController::class, 'contentBootstrap'])
            ->whereNumber('attempt')->where('path', '.*')->name('plugins.scorm.content.bootstrap');
        Route::get('/scorm-content/{attempt}/{path?}', [ScormRuntimeController::class, 'contentFile'])
            ->whereNumber('attempt')->where('path', '.*')->name('plugins.scorm.content.file');
    });
}
