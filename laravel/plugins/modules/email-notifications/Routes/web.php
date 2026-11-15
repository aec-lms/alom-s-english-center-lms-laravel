<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\EmailNotifications\Http\Controllers\EmailNotificationController;

Route::middleware(['web', 'installed', 'auth', 'plugin:email-notifications', 'permission:email.notifications.manage'])
    ->prefix('admin/email-notifications')
    ->name('plugins.email_notifications.')
    ->group(function (): void {
        Route::get('/', [EmailNotificationController::class, 'index'])->name('index');
        Route::post('/templates', [EmailNotificationController::class, 'storeTemplate'])->name('templates.store');
        Route::post('/send', [EmailNotificationController::class, 'send'])->name('send');
    });
