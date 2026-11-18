<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use Plugins\\Modules\\QuestionBank\\Http\\Controllers\\QuestionBankController;

Route::middleware(['web', 'installed', 'auth', 'plugin:question-bank', 'permission:question_bank.manage'])
    ->prefix('admin/question-bank')
    ->name('plugins.question-bank.')
    ->group(function (): void {
        Route::get('/', [QuestionBankController::class, 'index'])->name('index');
        Route::post('/questions', [QuestionBankController::class, 'storeQuestion'])->name('questions.store');
        Route::put('/questions/{question}', [QuestionBankController::class, 'updateQuestion'])->name('questions.update');
        Route::delete('/questions/{question}', [QuestionBankController::class, 'deleteQuestion'])->name('questions.destroy');
        Route::post('/papers', [QuestionBankController::class, 'buildPaper'])->name('papers.store');
        Route::get('/papers/{paper}', [QuestionBankController::class, 'showPaper'])->name('papers.show');
        Route::get('/papers/{paper}/print', [QuestionBankController::class, 'printPaper'])->name('papers.print');
        Route::get('/papers/{paper}/answer-key', [QuestionBankController::class, 'printAnswerKey'])->name('papers.answer-key');
    });
