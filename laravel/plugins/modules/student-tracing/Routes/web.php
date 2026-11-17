<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use Plugins\\Modules\\StudentTracing\\Http\\Controllers\\StudentTraceController;

Route::middleware(['web', 'installed', 'auth', 'plugin:student-tracing', 'permission:students.trace.view'])
    ->prefix('admin/student-tracing')
    ->name('plugins.student-tracing.')
    ->group(function (): void {
        Route::get('/', [StudentTraceController::class, 'index'])->name('index');
        Route::get('/export', [StudentTraceController::class, 'export'])
            ->middleware('permission:students.trace.export')
            ->name('export');
    });
