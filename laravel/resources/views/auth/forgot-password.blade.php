@extends('layouts.app')
@section('title', "Recover Password — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <section class="w-full max-w-[480px] rounded-2xl bg-white border border-white/90 px-7 sm:px-9 py-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)] space-y-5">
            <div class="text-center">
                <h1 class="text-xl font-extrabold text-slate-900">Forgot Password?</h1>
                <p class="mt-1 text-xs text-slate-500">Recover your Student, Manager, or Guardian account using an email link or a one-time code.</p>
            </div>

            @if (session('status'))
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-medium text-emerald-800">{{ session('status') }}</div>
            @endif
            @if (session('otp_status'))
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-medium text-emerald-800">{{ session('otp_status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-lg bg-rose-50 border border-rose-200 px-3.5 py-2.5 text-xs font-medium text-rose-700">{{ $errors->first() }}</div>
            @endif

            <div class="space-y-3">
                <h2 class="text-sm font-bold text-slate-800">Option 1: Gmail / Email recovery link</h2>
                <form method="POST" action="{{ route('password.email') }}" class="space-y-3">
                    @csrf
                    <input name="email" type="email" required value="{{ old('email') }}" autocomplete="email" placeholder="Registered Email Address" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                    <button type="submit" class="w-full py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold text-sm">Send Secure Recovery Link</button>
                </form>
            </div>

            <div class="border-t border-slate-100 pt-4 space-y-3">
                <h2 class="text-sm font-bold text-slate-800">Option 2: Email OTP recovery</h2>
                <form method="POST" action="{{ route('password.otp.send') }}" class="space-y-3">
                    @csrf
                    <label class="block text-xs font-semibold text-slate-600" for="otp-email">Registered email address</label>
                    <input id="otp-email" name="email" type="email" required value="{{ old('email') }}" autocomplete="email" placeholder="Registered Email Address" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                    <button type="submit" class="w-full py-2.5 rounded-lg border border-[#0057FF] text-[#0057FF] hover:bg-blue-50 font-bold text-sm">Send 6-Digit OTP</button>
                </form>

                <form method="POST" action="{{ route('password.otp.verify') }}" class="space-y-3 border-t border-slate-100 pt-4">
                    @csrf
                    <label class="block text-xs font-semibold text-slate-600" for="verify-email">Registered email address</label>
                    <input id="verify-email" name="email" type="email" required value="{{ old('email') }}" autocomplete="email" placeholder="Registered Email Address" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                    <label class="block text-xs font-semibold text-slate-600" for="recovery-otp">6-digit OTP</label>
                    <input id="recovery-otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code" placeholder="Enter OTP" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                    <input name="password" type="password" required minlength="10" autocomplete="new-password" placeholder="New Password (at least 10 characters)" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                    <input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" placeholder="Confirm New Password" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                    <button type="submit" class="w-full py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold text-sm">Verify OTP & Set New Password</button>
                </form>
                <p class="text-[11px] text-slate-500">OTP expires in 10 minutes, is single-use, and is limited to five verification attempts.</p>
            </div>

            <p class="text-center text-xs text-slate-500">
                <a href="{{ route('login') }}" class="font-bold text-[#2563EB] hover:underline">&larr; Back to Sign In</a>
            </p>
        </section>
    </main>

    @include('public.partials.footer')
</div>
@endsection
