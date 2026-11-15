@extends('layouts.app')
@section('title', $title . " — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[920px] mx-auto space-y-8">
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-7 sm:p-10 space-y-5">
                <span class="inline-block px-3 py-1 rounded-full bg-sky-50 text-[#0284C7] text-[11px] font-bold uppercase tracking-wider">{{ $eyebrow }}</span>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">{{ $title }}</h1>
                <div class="text-sm text-slate-600 leading-relaxed whitespace-pre-line">{{ $body }}</div>

                @if ($pageKey === 'about')
                    <div class="grid sm:grid-cols-3 gap-4 pt-4 border-t border-slate-100 text-xs">
                        <div class="p-4 rounded-xl bg-slate-50">
                            <div class="font-bold text-slate-900">Website Portal</div>
                            <div class="text-[#0284C7] font-mono mt-1">https://www.alomsenglishcenter.com</div>
                        </div>
                        <div class="p-4 rounded-xl bg-slate-50">
                            <div class="font-bold text-slate-900">Campus Location</div>
                            <div class="text-slate-600 mt-1">{{ $homepage['footer_address'] ?? 'Mirpur 10, Dhaka-1216' }}</div>
                        </div>
                        <div class="p-4 rounded-xl bg-slate-50">
                            <div class="font-bold text-slate-900">Support Desk</div>
                            <div class="text-slate-600 mt-1">{{ $homepage['footer_email'] ?? 'info@alomsenglishcenter.com' }}</div>
                        </div>
                    </div>
                @endif
            </div>

            @if ($pageKey === 'contact')
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-7 sm:p-10 space-y-5">
                    <h2 class="text-lg font-extrabold text-slate-900">Send Us a Message</h2>
                    @if (session('status'))
                        <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-xs font-semibold text-emerald-800">{{ session('status') }}</div>
                    @endif
                    <form method="POST" action="{{ route('public.contact.submit') }}" class="grid sm:grid-cols-2 gap-4 text-xs">
                        @csrf
                        <label class="block font-semibold">Full Name
                            <input name="name" required value="{{ old('name') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
                        </label>
                        <label class="block font-semibold">Email Address
                            <input name="email" type="email" required value="{{ old('email') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
                        </label>
                        <label class="block font-semibold">Phone Number
                            <input name="phone" value="{{ old('phone') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
                        </label>
                        <label class="block font-semibold">Subject
                            <input name="subject" required value="{{ old('subject') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
                        </label>
                        <label class="block font-semibold sm:col-span-2">Message
                            <textarea name="message" rows="4" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">{{ old('message') }}</textarea>
                        </label>
                        <div class="sm:col-span-2">
                            <button class="px-6 py-3 rounded-xl bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold">Submit Inquiry</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
