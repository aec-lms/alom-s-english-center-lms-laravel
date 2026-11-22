@extends('layouts.app')
@section('title', "Create Student Account — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <section class="w-full max-w-[460px] rounded-2xl bg-white border border-white/90 px-7 sm:px-9 py-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)]">
            <div class="text-center mb-6">
                <h1 class="text-xl sm:text-[22px] font-extrabold text-slate-900 tracking-tight">Create Your Learner Account</h1>
                <p class="mt-1 text-xs text-slate-500">Enroll in IELTS, Spoken English &amp; Grammar courses</p>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-3.5 py-2.5 text-xs font-medium text-rose-700">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('register.attempt') }}" class="space-y-3.5 text-sm">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Full Name</label>
                    <input name="name" type="text" required value="{{ old('name') }}" placeholder="e.g. Nusrat Jahan" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                    <input name="email" type="email" required value="{{ old('email') }}" placeholder="you@example.com" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Password (min 10 chars; uppercase, lowercase & number)</label>
                    <input name="password" type="password" required minlength="10" placeholder="Create password" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Confirm Password</label>
                    <input name="password_confirmation" type="password" required minlength="10" placeholder="Confirm password" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                </div>
                <button type="submit" class="w-full py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold shadow-sm transition-colors">
                    Register Now
                </button>
            </form>

            <p class="mt-5 text-center text-xs text-slate-500">
                Already have an account? <a href="{{ route('login') }}" class="font-bold text-[#2563EB] hover:underline">Sign In</a>
            </p>
        </section>
    </main>

    @include('public.partials.footer')
</div>
@endsection
