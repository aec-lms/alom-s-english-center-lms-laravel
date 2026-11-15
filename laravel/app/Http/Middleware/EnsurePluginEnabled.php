<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Plugins\PluginManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePluginEnabled
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        if (!$this->plugins->isEnabled($slug)) {
            abort(404, "Plugin '{$slug}' is not active.");
        }
        return $next($request);
    }
}
