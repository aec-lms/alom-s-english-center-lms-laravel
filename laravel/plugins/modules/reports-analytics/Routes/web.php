<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\ReportsAnalytics\Http\Controllers\ReportController;

Route::middleware(['web', 'installed', 'auth', 'plugin:reports-analytics', 'permission:reports.view'])
    ->prefix('admin/reports')
    ->name('plugins.reports.')
    ->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/audit-export', [ReportController::class, 'exportAuditCsv'])->name('audit.export');
    });
