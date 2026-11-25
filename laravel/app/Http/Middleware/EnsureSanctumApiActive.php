<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumApiActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        if (is_string($plainTextToken) && $plainTextToken !== '' && Schema::hasTable('personal_access_tokens')) {
            $token = PersonalAccessToken::findToken($plainTextToken);
            if ($token) {
                if ($token->expires_at !== null && $token->expires_at->isPast()) {
                    $token->delete();

                    return response()->json(['message' => 'Access token has expired. Please sign in or refresh your session.'], 401);
                }

                // Re-read the persisted account flag instead of trusting a cached
                // Sanctum tokenable relation which may outlive an account disable.
                if ($token->tokenable_type === (new User())->getMorphClass()
                    && Schema::hasTable('users')) {
                    $isActive = DB::table('users')
                        ->where('id', $token->tokenable_id)
                        ->value('is_active');

                    if ($isActive === null || !(bool) $isActive) {
                        return response()->json(['message' => 'Your account is currently inactive. Please contact the administrator.'], 403);
                    }
                }
            }
        }

        $user = $request->user('sanctum') ?? $request->user();
        if ($user && isset($user->is_active) && !(bool) $user->is_active) {
            return response()->json(['message' => 'Your account is currently inactive. Please contact the administrator.'], 403);
        }

        return $next($request);
    }
}
