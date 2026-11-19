<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCentralAdmin;
use App\Http\Middleware\EnsureBranchContext;
use App\Http\Middleware\EnsureDeviceSessionActive;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureNotInstalled;
use App\Http\Middleware\EnsurePluginEnabled;
use App\Http\Middleware\RolePermissionMiddleware;
use App\Http\Middleware\SetApplicationLocale;
use App\Console\Commands\OffsiteBackupCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([OffsiteBackupCommand::class])
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureDeviceSessionActive::class, EnsureBranchContext::class, SetApplicationLocale::class]);
        $middleware->api(append: [EnsureBranchContext::class]);
        $middleware->alias([
            'installed' => EnsureInstalled::class,
            'not.installed' => EnsureNotInstalled::class,
            'plugin' => EnsurePluginEnabled::class,
            'permission' => RolePermissionMiddleware::class,
            'central.admin' => EnsureCentralAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Core exception handling
    })
    ->create();
