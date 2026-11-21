<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\DeviceSession;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordRecoveryOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = strtolower($credentials['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors(['email' => "Too many login attempts. Retry in {$seconds} seconds."]);
        }

        if (!Auth::attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 900);
            $this->audit->log('auth.login_failed', 'user', hash('sha256', strtolower($credentials['email'])));
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Invalid credentials or inactive account.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        if (Schema::hasTable('device_sessions')) {
            DeviceSession::register($request, $request->user());
        }
        $this->audit->log('auth.login_succeeded', 'user', (string) Auth::id());

        $defaultDestination = Auth::user()?->hasRole('admin') ? route('admin.dashboard') : route('portal.dashboard');
        return redirect()->intended($defaultDestination);
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->letters()->mixedCase()->numbers()],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        if (Schema::hasTable('roles') && Schema::hasTable('role_user')) {
            $studentRole = Role::query()->where('slug', 'student')->first();
            if ($studentRole) {
                $user->roles()->syncWithoutDetaching([$studentRole->id]);
            }
        }

        Auth::login($user);
        $request->session()->regenerate();
        if (Schema::hasTable('device_sessions')) {
            DeviceSession::register($request, $user);
        }
        $this->audit->log('auth.registered', 'user', (string) $user->id);

        return redirect()->route('portal.dashboard')->with('status', "Welcome to Alom's English Center, {$user->name}!");
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetNotice(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($validated['email'])]);

        // Keep the response identical for known and unknown addresses to prevent account enumeration.
        $this->audit->log('auth.password_reset_requested', 'user', hash('sha256', strtolower($validated['email'])));

        return back()->with('status', 'If an account matches that email, password recovery instructions will be sent shortly.');
    }

    public function sendRecoveryOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:150']]);
        $email = strtolower(trim($validated['email']));
        $key = 'password-recovery-otp-send:' . hash('sha256', $email . '|' . $request->ip());
        $emailKey = 'password-recovery-otp-send-email:' . hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($key, 3) || RateLimiter::tooManyAttempts($emailKey, 5)) {
            return back()->withErrors(['otp_email' => 'Too many recovery requests. Please try again later.']);
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($emailKey, 3600);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->first();
        if ($user) {
            $code = (string) random_int(100000, 999999);

            DB::table('password_recovery_otps')->where('user_id', $user->id)->whereNull('consumed_at')->delete();
            DB::table('password_recovery_otps')->insert([
                'user_id' => $user->id,
                'otp_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $user->notify(new PasswordRecoveryOtp($code));
            } catch (\Throwable $exception) {
                DB::table('password_recovery_otps')->where('user_id', $user->id)->whereNull('consumed_at')->delete();
                report($exception);
            }
        }

        $this->audit->log('auth.password_otp_requested', 'user', hash('sha256', $email));
        return back()->with('otp_status', 'If an active account matches that email, a recovery code will be sent. The code expires in 10 minutes.');
    }

    public function verifyRecoveryOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $email = strtolower(trim($validated['email']));
        $key = 'password-recovery-otp-verify:' . hash('sha256', $email . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['otp' => 'Too many incorrect attempts. Please request a new recovery code later.']);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->first();
        $verified = false;

        if ($user) {
            $verified = DB::transaction(function () use ($user, $validated): bool {
                $recovery = DB::table('password_recovery_otps')
                    ->where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (!$recovery || now()->greaterThanOrEqualTo($recovery->expires_at) || (int) $recovery->attempts >= 5) {
                    return false;
                }

                if (!Hash::check($validated['otp'], $recovery->otp_hash)) {
                    DB::table('password_recovery_otps')->where('id', $recovery->id)->increment('attempts');
                    return false;
                }

                DB::table('password_recovery_otps')->where('id', $recovery->id)->update([
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table('password_recovery_otps')->where('user_id', $user->id)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();

                if (Schema::hasTable('device_sessions')) {
                    DeviceSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update([
                        'revoked_at' => now(),
                        'revoke_reason' => 'Password recovered',
                        'updated_at' => now(),
                    ]);
                }
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                if (Schema::hasTable('personal_access_tokens')) {
                    DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
                }

                return true;
            });
        }

        if (!$verified) {
            RateLimiter::hit($key, 900);
            return back()->withErrors(['otp' => 'The recovery code is invalid, expired, or unavailable. Request a new code and try again.']);
        }

        RateLimiter::clear($key);
        $this->audit->log('auth.password_reset_completed_via_otp', 'user', hash('sha256', $email));
        return redirect()->route('login')->with('status', 'Your password has been reset. You can now sign in.');
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $status = Password::reset(
            [
                'email' => strtolower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table('password_recovery_otps')->where('user_id', $user->id)->delete();
                if (Schema::hasTable('device_sessions')) {
                    DeviceSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update([
                        'revoked_at' => now(),
                        'revoke_reason' => 'Password recovered',
                        'updated_at' => now(),
                    ]);
                }
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                if (Schema::hasTable('personal_access_tokens')) {
                    DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
                }
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $this->audit->log('auth.password_reset_completed', 'user', hash('sha256', strtolower($validated['email'])));
            return redirect()->route('login')->with('status', 'Your password has been reset. You can now sign in.');
        }

        return back()->withInput($request->only('email'))->withErrors([
            'email' => __($status),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->log('auth.logout', 'user', (string) Auth::id());
        if (Auth::check() && Schema::hasTable('device_sessions')) {
            DeviceSession::query()
                ->where('user_id', Auth::id())
                ->where('session_hash', DeviceSession::currentHash($request))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoke_reason' => 'User logged out', 'updated_at' => now()]);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
