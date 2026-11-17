<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MediaLibrary\Http\Controllers\MediaLibraryController;

Route::middleware(['web', 'installed', 'auth', 'plugin:media-library', 'permission:media.manage'])
    ->prefix('admin/media')
    ->name('plugins.media.')
    ->group(function (): void {
        Route::get('/', [MediaLibraryController::class, 'index'])->name('index');
        Route::post('/', [MediaLibraryController::class, 'store'])->name('store');
        Route::get('/{asset}/download', [MediaLibraryController::class, 'download'])->name('download');
        Route::delete('/{asset}', [MediaLibraryController::class, 'destroy'])->name('destroy');
    });