<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\LearningRewards\Http\Controllers\LearningRewardsController;

Route::middleware(['web', 'installed', 'plugin:learning-rewards', 'auth'])->group(function (): void {
    Route::get('/my-learning-rewards', [LearningRewardsController::class, 'dashboard'])->name('plugins.learning-rewards.dashboard');
    Route::get('/learning-leaderboard', [LearningRewardsController::class, 'leaderboard'])->name('plugins.learning-rewards.leaderboard');
    Route::patch('/my-learning-rewards/privacy', [LearningRewardsController::class, 'updatePrivacy'])->name('plugins.learning-rewards.privacy');

    Route::prefix('admin/learning-rewards')->name('plugins.learning-rewards.admin.')
        ->middleware('permission:gamification.manage')
        ->group(function (): void {
            Route::get('/', [LearningRewardsController::class, 'adminSettings'])->name('settings');
            Route::put('/rules', [LearningRewardsController::class, 'updateRules'])->name('rules.update');
        });
});
