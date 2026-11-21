<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumApiActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user) return $next($request);
        if (isset($user->is_active) && !(bool) $user->is_active) {
            return response()->json(['message' => 'Your account is currently inactive. Please contact the administrator.'], 403);
        }
        $plainTextToken = $request->bearerToken();
        if (is_string($plainTextToken) && $plainTextToken !== '' && Schema::hasTable('personal_access_tokens')) {
            $token = PersonalAccessToken::findToken($plainTextToken);
            if ($token && $token->tokenable_type === $user->getMorphClass()
                && (int) $token->tokenable_id === (int) $user->getAuthIdentifier()
                && $token->expires_at !== null && $token->expires_at->isPast()) {
                $token->delete();
                return response()->json(['message' => 'Access token has expired. Please sign in or refresh your session.'], 401);
            }
        }
        return $next($request);
    }
}
