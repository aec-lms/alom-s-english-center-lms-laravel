<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\NoticeBoard\Http\Controllers\NoticeController;

Route::middleware(['web', 'installed', 'auth', 'plugin:notice-board', 'permission:notices.manage'])
    ->prefix('admin/notices')
    ->name('plugins.notices.')
    ->group(function (): void {
        Route::get('/', [NoticeController::class, 'index'])->name('index');
        Route::post('/', [NoticeController::class, 'store'])->name('store');
    });
