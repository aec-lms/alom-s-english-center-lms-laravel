<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RolePermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (!$user || !$user->is_active || !$user->hasPermission($permission)) {
            abort(403, 'Unauthorized action.');
        }
        if ($user->hasRole('branch_manager') && (int) $user->branch_id <= 0) {
            abort(403, 'Branch manager account must be assigned to an active branch.');
        }

        return $next($request);
    }
}
