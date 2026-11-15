@extends('layouts.app')
@section('title', "Recover Password — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <section class="w-full max-w-[440px] rounded-2xl bg-white border border-white/90 px-7 sm:px-9 py-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)] space-y-5">
            <div class="text-center">
                <h1 class="text-xl font-extrabold text-slate-900">Forgot Password?</h1>
                <p class="mt-1 text-xs text-slate-500">Enter your registered email address and we will queue recovery instructions.</p>
            </div>

            @if (session('status'))
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-3.5 py-2.5 text-xs font-medium text-emerald-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-lg bg-rose-50 border border-rose-200 px-3.5 py-2.5 text-xs font-medium text-rose-700">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
                @csrf
                <input name="email" type="email" required value="{{ old('email') }}" placeholder="Registered Email Address" class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15">
                <button type="submit" class="w-full py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold text-sm">Send Recovery Request</button>
            </form>

            <p class="text-center text-xs text-slate-500">
                <a href="{{ route('login') }}" class="font-bold text-[#2563EB] hover:underline">&larr; Back to Sign In</a>
            </p>
        </section>
    </main>

    @include('public.partials.footer')
</div>
@endsection
