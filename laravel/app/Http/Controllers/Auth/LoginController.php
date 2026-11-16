<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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
        $this->audit->log('auth.login_succeeded', 'user', (string) Auth::id());

        return redirect()->intended(route('portal.dashboard'));
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
