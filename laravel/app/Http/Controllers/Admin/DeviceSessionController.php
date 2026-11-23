<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\DeviceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeviceSessionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'role' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,expired,revoked'],
        ]);

        $cutoff = now()->subMinutes((int) config('session.lifetime', 120));
        $sessions = DeviceSession::query()
            ->with('user:id,name,email,branch_id')
            ->when($filters['role'] ?? null, fn ($query, string $role) =>
                $query->whereHas('user.roles', fn ($roles) => $roles->where('slug', $role))
            )
            ->when(($filters['status'] ?? null) === 'active', fn ($query) =>
                $query->whereNull('revoked_at')->where('last_seen_at', '>=', $cutoff)
            )
            ->when(($filters['status'] ?? null) === 'revoked', fn ($query) => $query->whereNotNull('revoked_at'))
            ->when(($filters['status'] ?? null) === 'expired', fn ($query) =>
                $query->whereNull('revoked_at')->where('last_seen_at', '<', $cutoff)
            )
            ->latest('last_seen_at')
            ->paginate(30)
            ->withQueryString();

        $counts = [
            'active' => DeviceSession::whereNull('revoked_at')->where('last_seen_at', '>=', $cutoff)->count(),
            'revoked' => DeviceSession::whereNotNull('revoked_at')->count(),
            'expired' => DeviceSession::whereNull('revoked_at')->where('last_seen_at', '<', $cutoff)->count(),
        ];

        return view('admin.security.device-sessions', compact('sessions', 'counts', 'filters'));
    }

    public function revoke(DeviceSession $deviceSession, Request $request): RedirectResponse
    {
        $currentHash = DeviceSession::currentHash($request);
        abort_unless(
            (int) $deviceSession->user_id !== (int) $request->user()->getAuthIdentifier()
                || !hash_equals((string) $deviceSession->session_hash, $currentHash),
            422,
            __('You cannot revoke the session currently being used to administer the LMS.')
        );

        $revoked = DB::transaction(function () use ($deviceSession, $request): int {
            $revoked = DB::table('device_sessions')
                ->where('id', $deviceSession->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoke_reason' => 'Signed out by administrator '.$request->user()->getAuthIdentifier(),
                    'updated_at' => now(),
                ]);

            if ($revoked > 0) {
                $this->audit->logRequired(
                    'security.device_session_revoked',
                    'device_session',
                    (string) $deviceSession->id,
                    [],
                    (int) $request->user()->getAuthIdentifier(),
                );
            }

            return $revoked;
        });

        if ($revoked > 0) {
            return back()->with('success', __('The selected session has been revoked.'));
        }

        return back()->with('info', __('This session is already revoked.'));
    }
}
