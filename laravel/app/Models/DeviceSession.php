<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSession extends Model
{
    protected $fillable = [
        'user_id', 'session_hash', 'device_name', 'user_agent', 'ip_address',
        'logged_in_at', 'last_seen_at', 'revoked_at', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function register($request, User $user): self
    {
        $hash = hash('sha256', $request->session()->getId());
        $request->session()->put('device_session_hash', $hash);

        return self::updateOrCreate(
            ['session_hash' => $hash],
            [
                'user_id' => $user->id,
                'device_name' => self::describeAgent($request->userAgent()),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'ip_address' => $request->ip(),
                'logged_in_at' => now(),
                'last_seen_at' => now(),
                'revoked_at' => null,
                'revoke_reason' => null,
            ]
        );
    }

    public static function currentHash($request): string
    {
        $hash = $request->session()->get('device_session_hash');
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return $hash;
        }

        return hash('sha256', $request->session()->getId());
    }

    private static function describeAgent(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);
        $browser = str_contains($agent, 'edg/') ? 'Edge'
            : (str_contains($agent, 'chrome/') ? 'Chrome'
            : (str_contains($agent, 'firefox/') ? 'Firefox'
            : (str_contains($agent, 'safari/') ? 'Safari' : 'Unknown browser')));
        $platform = str_contains($agent, 'android') ? 'Android'
            : (str_contains($agent, 'iphone') || str_contains($agent, 'ipad') ? 'iOS'
            : (str_contains($agent, 'windows') ? 'Windows'
            : (str_contains($agent, 'mac os') ? 'macOS'
            : (str_contains($agent, 'linux') ? 'Linux' : 'Unknown device'))));

        return $platform.' · '.$browser;
    }
}
