<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Installer\FreshInstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotInstalled
{
    public function __construct(private readonly FreshInstallerService $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->isInstalled()) {
            abort(404);
        }
        return $next($request);
    }
}
