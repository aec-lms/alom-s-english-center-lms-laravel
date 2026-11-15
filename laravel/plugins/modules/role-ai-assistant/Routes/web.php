<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\RoleAiAssistant\Http\Controllers\AiAssistantController;

Route::middleware(['web', 'installed', 'auth', 'plugin:role-ai-assistant'])
    ->prefix('ai-assistant')
    ->name('plugins.ai.')
    ->group(function (): void {
        Route::get('/', [AiAssistantController::class, 'index'])
            ->name('index')
            ->middleware('permission:ai.assistant.use');
        Route::post('/ask', [AiAssistantController::class, 'ask'])->name('ask');
    });
