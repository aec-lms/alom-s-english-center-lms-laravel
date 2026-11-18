<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\SupportCenter\Http\Controllers\SupportTicketController;

Route::middleware(['api', 'installed', 'plugin:support-center', 'auth:sanctum'])
    ->prefix('api/v1/my/support-tickets')
    ->name('plugins.support.api.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'apiIndex'])->name('index');
        Route::post('/', [SupportTicketController::class, 'apiStore'])->middleware('throttle:10,1')->name('store');
        Route::get('/{ticket}', [SupportTicketController::class, 'apiShow'])->whereNumber('ticket')->name('show');
        Route::post('/{ticket}/messages', [SupportTicketController::class, 'apiReply'])->whereNumber('ticket')->middleware('throttle:10,1')->name('reply');
    });
