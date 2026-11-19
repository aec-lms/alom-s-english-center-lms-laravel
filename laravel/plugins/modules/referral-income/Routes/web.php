<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\ReferralIncome\Http\Controllers\ReferralIncomeController;

Route::middleware(['web', 'installed', 'plugin:referral-income'])->get('/r/{code}', [ReferralIncomeController::class, 'track'])->where('code', '[A-Za-z0-9]{6,32}')->name('plugins.referral_income.track');

Route::middleware(['web', 'installed', 'auth', 'plugin:referral-income'])
    ->prefix('my-referrals')->name('plugins.referral_income.member.')
    ->group(function (): void {
        Route::get('/', [ReferralIncomeController::class, 'dashboard'])->name('dashboard');
        Route::post('/apply', [ReferralIncomeController::class, 'apply'])->middleware('throttle:5,1')->name('apply');
        Route::post('/payout', [ReferralIncomeController::class, 'requestPayout'])->middleware('throttle:3,1')->name('payout');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:referral-income', 'central.admin', 'permission:referral_income.manage'])
    ->prefix('admin/referral-income')->name('plugins.referral_income.admin.')
    ->group(function (): void {
        Route::get('/', [ReferralIncomeController::class, 'adminIndex'])->name('index');
        Route::put('/rules', [ReferralIncomeController::class, 'updateRules'])->name('rules.update');
        Route::post('/commissions/{commission}/review', [ReferralIncomeController::class, 'reviewCommission'])->whereNumber('commission')->name('commissions.review');
        Route::post('/recoveries/{reversal}/review', [ReferralIncomeController::class, 'reviewRecovery'])->whereNumber('reversal')->name('recoveries.review');
        Route::post('/payouts/{payout}/review', [ReferralIncomeController::class, 'reviewPayout'])->whereNumber('payout')->name('payouts.review');
    });