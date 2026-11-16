@extends('layouts.app')
@section('title', "Reset Password — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')
    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <section class="w-full max-w-[440px] rounded-2xl bg-white border border-white/90 px-7 sm:px-9 py-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)] space-y-5">
            <div class="text-center">
                <h1 class="text-xl font-extrabold text-slate-900">Create a New Password</h1>
                <p class="mt-1 text-xs text-slate-500">Choose a strong password with at least 10 characters.</p>
            </div>
            @if ($errors->any())
                <div class="rounded-lg bg-rose-50 border border-rose-200 px-3.5 py-2.5 text-xs font-medium text-rose-700">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input name="email" type="email" required value="{{ old('email', $email) }}" placeholder="Registered Email Address" autocomplete="email" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                <input name="password" type="password" required minlength="10" autocomplete="new-password" placeholder="New Password" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                <input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" placeholder="Confirm New Password" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                <button type="submit" class="w-full py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold text-sm">Reset Password</button>
            </form>
            <p class="text-center text-xs text-slate-500"><a href="{{ route('login') }}" class="font-bold text-[#2563EB] hover:underline">Back to Sign In</a></p>
        </section>
    </main>
    @include('public.partials.footer')
</div>
@endsection
