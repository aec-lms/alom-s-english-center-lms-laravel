<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\MarketingManagement\Http\Controllers\MarketingController;

Route::middleware(['web', 'installed', 'auth', 'plugin:marketing-management', 'permission:marketing.manage'])
    ->prefix('admin/marketing')
    ->name('plugins.marketing.')
    ->group(function (): void {
        Route::get('/', [MarketingController::class, 'index'])->name('index');
        Route::post('/campaigns', [MarketingController::class, 'storeCampaign'])->name('campaigns.store');
        Route::post('/coupons', [MarketingController::class, 'storeCoupon'])->name('coupons.store');
    });
