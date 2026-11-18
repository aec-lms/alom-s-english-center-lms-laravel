<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\DeviceSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceSessionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !Schema::hasTable('device_sessions')) {
            return $next($request);
        }

        $session = DeviceSession::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('session_hash', DeviceSession::currentHash($request))
            ->first();

        // Existing sessions created before this feature remain valid until next login.
        if (!$session) {
            return $next($request);
        }

        if ($session->revoked_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __('This login session was revoked. Please sign in again.'));
        }

        if (!$session->last_seen_at || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill([
                'last_seen_at' => now(),
                'ip_address' => $request->ip(),
            ])->save();
        }

        return $next($request);
    }
}
