<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\NotificationCenter\Http\Controllers\NotificationController;

Route::middleware(['web', 'installed', 'auth', 'plugin:notification-center', 'permission:notifications.manage'])
    ->prefix('admin/notifications')
    ->name('plugins.notifications.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/dispatch', [NotificationController::class, 'dispatch'])->name('dispatch');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:notification-center'])
    ->prefix('my-notifications')
    ->name('my-notifications.')
    ->group(function (): void {
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });
