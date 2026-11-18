<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Security\BranchAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchContext
{
    public function __construct(private readonly BranchAccess $branches) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A revoked/inactive branch manager must still be able to terminate the session/token.
        if ($request->isMethod('POST') && (str_ends_with($request->path(), '/logout') || $request->path() === 'logout')) {
            return $next($request);
        }

        $actor = $request->user() ?? $request->user('sanctum');
        if ($actor && $this->branches->isBranchManager($actor)) {
            $request->attributes->set('assigned_branch_id', $this->branches->assignedBranchId($actor));
        }

        return $next($request);
    }
}
