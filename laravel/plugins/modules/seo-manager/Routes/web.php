<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\SeoManager\Http\Controllers\SeoController;

Route::middleware(['web', 'installed', 'plugin:seo-manager'])->group(function (): void {
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('plugins.seo.sitemap');
    Route::get('/robots.txt', [SeoController::class, 'robotsTxt'])->name('plugins.seo.robots');
});

Route::middleware(['web', 'installed', 'auth', 'plugin:seo-manager', 'permission:seo.manage', 'central.admin'])
    ->prefix('plugins/seo')
    ->name('plugins.seo.')
    ->group(function (): void {
        Route::get('/', [SeoController::class, 'index'])->name('index');
        Route::post('/global', [SeoController::class, 'saveGlobal'])->name('global');
        Route::post('/rules', [SeoController::class, 'storeRule'])->name('rules.store');
        Route::delete('/rules/{rule}', [SeoController::class, 'destroyRule'])->name('rules.destroy');
    });
