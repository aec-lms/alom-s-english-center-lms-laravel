@extends('layouts.app')
@section('title', __('Sign In') . ' — ' . config('app.name'))
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <section class="w-full max-w-[440px] rounded-2xl bg-white border border-white/90 px-7 sm:px-9 py-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)]">
            <a href="{{ route('home') }}" class="mx-auto mb-5 flex items-center justify-center gap-2.5">
                @if (!empty($siteIdentity['logo_url']))
                    <img src="{{ $siteIdentity['logo_url'] }}" alt="{{ config('app.name') }} logo" class="h-10 max-w-36 object-contain">
                @else
                    <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#0284C7] to-[#4F46E5] text-white font-black text-base flex items-center justify-center shadow-sm">A</span>
                @endif
                <span class="text-left leading-[1.05]">
                    <span class="block text-base font-black tracking-tight text-slate-900">{{ config('app.name') }}</span>
                    <span class="block text-xs font-extrabold tracking-tight text-slate-800">{{ ($siteIdentity['tagline'] ?? null) === 'Learn English with confidence' ? __('Learn English with confidence') : ($siteIdentity['tagline'] ?? '') }}</span>
                </span>
            </a>

            <div class="text-center mb-6">
                <h1 class="text-xl sm:text-[22px] font-extrabold text-slate-900 tracking-tight">{{ ($siteIdentity['login_title'] ?? null) === 'Welcome Back!' ? __('Welcome Back!') : ($siteIdentity['login_title'] ?? __('Welcome Back!')) }}</h1>
                <p class="mt-1 text-xs text-slate-500">{{ ($siteIdentity['login_message'] ?? null) === 'Please sign in to continue' ? __('Please sign in to continue') : ($siteIdentity['login_message'] ?? __('Please sign in to continue')) }}</p>
            </div>

            @if (session('status'))
                <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-medium text-emerald-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-3.5 py-2.5 text-xs font-medium text-rose-700">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-3.5">
                @csrf
                <div class="relative">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <input
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="{{ __('Email Address') }}"
                        value="{{ old('email') }}"
                        class="w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15 transition-colors"
                    >
                </div>

                <div class="relative">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <input
                        id="login-password-input"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="{{ __('Password') }}"
                        class="w-full rounded-lg border border-slate-200 bg-white pl-10 pr-10 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15 transition-colors"
                    >
                    <button
                        type="button"
                        id="toggle-password-visibility"
                        aria-label="{{ __('Toggle password visibility') }}"
                        class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600"
                    >
                        <svg id="eye-icon-open" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>

                <div class="flex items-center justify-between text-xs pt-1">
                    <label class="inline-flex items-center gap-1.5 text-slate-600">
                        <input type="checkbox" name="remember" value="1" class="rounded border-slate-300"> {{ __('Keep me signed in') }}
                    </label>
                    <a href="{{ route('password.request') }}" class="font-semibold text-[#2563EB] hover:underline">{{ __('Forgot password?') }}</a>
                </div>

                <button type="submit" class="w-full py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold text-sm shadow-sm transition-colors">
                    {{ __('Sign In') }}
                </button>
            </form>

            <div class="my-5 flex items-center gap-3">
                <div class="h-px flex-1 bg-slate-200"></div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ __('OR') }}</span>
                <div class="h-px flex-1 bg-slate-200"></div>
            </div>

            <div class="text-center text-xs text-slate-500 space-y-2">
                <div>
                    {{ __("New to Alom's English Center?") }}
                    <a href="{{ route('register') }}" class="font-bold text-[#2563EB] hover:underline">{{ __('Create Student Account') }}</a>
                </div>
                <div>
                    {{ __('Browse courses as a guest?') }}
                    <a href="{{ route('public.courses') }}" class="font-bold text-slate-700 hover:underline">{{ __('View Courses') }}</a>
                </div>
            </div>

            <p class="mt-5 text-center text-[10px] leading-relaxed text-slate-400">
                {{ __('Protected login for Students, Teachers, Guardians & Administrators.') }}
            </p>
        </section>
    </main>

    @include('public.partials.footer')
</div>

<script>
(() => {
    const input = document.getElementById('login-password-input');
    const toggle = document.getElementById('toggle-password-visibility');
    if (input && toggle) {
        toggle.addEventListener('click', () => {
            input.type = input.type === 'password' ? 'text' : 'password';
        });
    }
})();
</script>
@endsection
