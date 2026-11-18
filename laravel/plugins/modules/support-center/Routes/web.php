<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\SupportCenter\Http\Controllers\SupportTicketController;

Route::middleware(['web', 'installed', 'auth', 'plugin:support-center'])
    ->prefix('my/support-tickets')
    ->name('plugins.support.student.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'studentIndex'])->name('index');
        Route::post('/', [SupportTicketController::class, 'studentStore'])->middleware('throttle:10,1')->name('store');
        Route::get('/{ticket}', [SupportTicketController::class, 'studentShow'])->whereNumber('ticket')->name('show');
        Route::post('/{ticket}/messages', [SupportTicketController::class, 'studentReply'])->whereNumber('ticket')->middleware('throttle:10,1')->name('reply');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:support-center', 'permission:support.tickets.manage'])
    ->prefix('admin/support-tickets')
    ->name('plugins.support.admin.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'adminIndex'])->name('index');
        Route::get('/{ticket}', [SupportTicketController::class, 'adminShow'])->whereNumber('ticket')->name('show');
        Route::post('/{ticket}/messages', [SupportTicketController::class, 'adminReply'])->whereNumber('ticket')->name('reply');
        Route::patch('/{ticket}/status', [SupportTicketController::class, 'adminUpdateStatus'])->whereNumber('ticket')->name('status');
    });
