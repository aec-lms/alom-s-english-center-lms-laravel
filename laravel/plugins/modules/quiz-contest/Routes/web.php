<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\QuizContest\Http\Controllers\QuizContestController;

Route::middleware(['web', 'installed', 'plugin:quiz-contest'])->group(function (): void {
    Route::get('/quiz-contests', [QuizContestController::class, 'index'])->name('plugins.quiz-contest.index');
    Route::get('/quiz-contests/{contest}', [QuizContestController::class, 'show'])->whereNumber('contest')->name('plugins.quiz-contest.show');
    Route::post('/quiz-contests/{contest}/submit', [QuizContestController::class, 'submit'])->whereNumber('contest')->middleware('auth')->name('plugins.quiz-contest.submit');

    Route::prefix('admin/quiz-contests')->name('plugins.quiz-contest.admin.')
        ->middleware(['auth', 'permission:quiz.contests.manage'])
        ->group(function (): void {
            Route::get('/', [QuizContestController::class, 'adminIndex'])->name('index');
            Route::post('/', [QuizContestController::class, 'store'])->name('store');
            Route::get('/{contest}/edit', [QuizContestController::class, 'edit'])->whereNumber('contest')->name('edit');
            Route::put('/{contest}', [QuizContestController::class, 'update'])->whereNumber('contest')->name('update');
            Route::patch('/{contest}/status', [QuizContestController::class, 'updateStatus'])->whereNumber('contest')->name('status');
            Route::get('/{contest}/entries', [QuizContestController::class, 'adminEntries'])->whereNumber('contest')->name('entries');
            Route::post('/{contest}/publish-results', [QuizContestController::class, 'publishResults'])->whereNumber('contest')->name('publish-results');
        });
});
