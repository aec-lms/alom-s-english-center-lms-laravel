@extends('layouts.app')
@section('title', 'Fresh Installation — AEC LMS')
@section('content')
<div class="min-h-screen flex items-center justify-center p-6 bg-gradient-to-b from-[#022D41] via-[#032638] to-[#0B1220]">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl border border-white/15 p-8 space-y-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-[#0284C7]">International LMS Installer</span>
                <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Install Your LMS (Laravel 12)</h1>
                <p class="text-xs text-slate-600 mt-1">Requires an empty fresh database. Legacy tables or old operational records are strictly never imported.</p>
            </div>
            <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#0284C7] to-[#2563EB] text-white font-black text-base flex items-center justify-center shrink-0">A</span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-xs">
            <div class="font-bold text-slate-800">Server &amp; Directory Requirements</div>
            <div>PHP {{ $requirements['php_version'] }}: <strong class="{{ $requirements['php_ok'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $requirements['php_ok'] ? 'OK' : 'Requires 8.3+' }}</strong></div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                @foreach ($requirements['extensions'] as $ext => $loaded)
                    <div class="px-2 py-1 rounded border {{ $loaded ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' }}">{{ $ext }}: {{ $loaded ? 'OK' : 'Missing' }}</div>
                @endforeach
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('installer.store') }}" class="space-y-4 text-sm">
            @csrf
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Application Name</span>
                    <input name="app_name" required value="{{ old('app_name', 'My Learning Platform') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Application URL</span>
                    <input name="app_url" type="url" required value="{{ old('app_url', 'https://example.com') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Country Code (ISO 2-letter)</span>
                    <input name="country_code" required maxlength="2" pattern="[A-Za-z]{2}" value="{{ old('country_code', 'US') }}" placeholder="US, GB, BD" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Language / Locale</span>
                    <input name="locale" required maxlength="35" value="{{ old('locale', 'en') }}" placeholder="en, en-US, bn-BD" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Timezone (IANA)</span>
                    <input name="timezone" required value="{{ old('timezone', 'UTC') }}" placeholder="UTC, Europe/London, Asia/Dhaka" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Default Currency (ISO 4217)</span>
                    <input name="currency" required maxlength="3" pattern="[A-Za-z]{3}" value="{{ old('currency', 'USD') }}" placeholder="USD, EUR, BDT" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Administrator Name</span>
                    <input name="admin_name" required value="{{ old('admin_name') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Administrator Email</span>
                    <input name="admin_email" type="email" required value="{{ old('admin_email') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Password (min 12 chars)</span>
                    <input name="admin_password" type="password" required minlength="12" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-slate-700">Confirm Password</span>
                    <input name="admin_password_confirmation" type="password" required minlength="12" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <button class="w-full py-3 rounded-xl bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold text-sm transition-colors">Validate Fresh Database &amp; Install Core</button>
        </form>
    </div>
</div>
@endsection
