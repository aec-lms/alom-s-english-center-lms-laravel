<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use Plugins\\Modules\\SupportCenter\\Http\\Controllers\\SupportTicketController;

Route::middleware(['web', 'installed', 'auth', 'plugin:support-center'])
    ->group(function (): void {
        Route::prefix('support/tickets')->name('plugins.support.student.')->group(function (): void {
            Route::get('/', [SupportTicketController::class, 'studentIndex'])->name('index');
            Route::post('/', [SupportTicketController::class, 'studentStore'])->name('store');
            Route::get('/{ticket}', [SupportTicketController::class, 'studentShow'])->whereNumber('ticket')->name('show');
            Route::post('/{ticket}/replies', [SupportTicketController::class, 'studentReply'])->whereNumber('ticket')->name('reply');
        });

        Route::prefix('admin/support-tickets')->name('plugins.support.admin.')
            ->middleware('permission:support.tickets.manage')
            ->group(function (): void {
                Route::get('/', [SupportTicketController::class, 'adminIndex'])->name('index');
                Route::get('/{ticket}', [SupportTicketController::class, 'adminShow'])->whereNumber('ticket')->name('show');
                Route::post('/{ticket}/replies', [SupportTicketController::class, 'adminReply'])->whereNumber('ticket')->name('reply');
                Route::patch('/{ticket}/status', [SupportTicketController::class, 'adminUpdateStatus'])->whereNumber('ticket')->name('status');
            });
    });
