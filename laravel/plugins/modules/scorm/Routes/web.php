<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\Scorm\Http\Controllers\ScormPackageController;
use Plugins\Modules\Scorm\Http\Controllers\ScormRuntimeController;

Route::middleware(['web', 'installed', 'plugin:scorm'])->group(function (): void {
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

$contentOrigin = (string) config('scorm.content_origin', '');
$contentHost = parse_url($contentOrigin, PHP_URL_HOST);
$appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
if (is_string($contentHost) && $contentHost !== '' && $contentHost !== $appHost) {
    Route::domain($contentHost)->middleware(['web', 'installed', 'plugin:scorm'])->group(function (): void {
        Route::get('/scorm-player/{attempt}/{token}', [ScormRuntimeController::class, 'contentPlayer'])
            ->whereNumber('attempt')->name('plugins.scorm.content.player')->middleware('signed');
        Route::get('/scorm-content/{attempt}/{token}/{path?}', [ScormRuntimeController::class, 'contentFile'])
            ->whereNumber('attempt')->where('path', '.*')->name('plugins.scorm.content.file');
    });
}
