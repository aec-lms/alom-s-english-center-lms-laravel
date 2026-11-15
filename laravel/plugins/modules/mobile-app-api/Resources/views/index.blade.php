@extends('layouts.admin')
@section('title', 'Mobile App Control (iOS & Android) — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Mobile App Management (Apple iOS &amp; Android)</h1>
            <p class="text-xs text-slate-500">Control store links, version gates, deep links, and Sanctum REST endpoints for <span class="font-mono">https://www.alomsenglishcenter.com/api/mobile/*</span>.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="px-3 py-1 rounded-full bg-slate-900 text-white font-bold">iOS Devices: {{ $iosCount }}</span>
            <span class="px-3 py-1 rounded-full bg-emerald-600 text-white font-bold">Android Devices: {{ $androidCount }}</span>
        </div>
    </div>

    <form method="POST" action="{{ route('plugins.mobile_app.save') }}" class="grid md:grid-cols-2 gap-6 text-xs">
        @csrf
        <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
            <div class="flex items-center justify-between border-b pb-3">
                <div>
                    <h2 class="text-sm font-extrabold text-slate-900">Apple iOS App Store</h2>
                    <p class="text-[11px] text-slate-500">iPhone &amp; iPad native client settings</p>
                </div>
                <label class="inline-flex items-center gap-1.5 font-semibold"><input type="checkbox" name="ios_enabled" value="1" @checked($config['ios_enabled'] ?? true)> Enabled</label>
            </div>
            <label class="block font-semibold">Apple Bundle Identifier
                <input name="ios_bundle_id" required value="{{ $config['ios_bundle_id'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
            <label class="block font-semibold">Apple App Store URL
                <input name="ios_store_url" value="{{ $config['ios_store_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block font-semibold">Min Supported Version
                    <input name="ios_min_version" required value="{{ $config['ios_min_version'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
                </label>
                <label class="block font-semibold">Latest App Store Version
                    <input name="ios_latest_version" required value="{{ $config['ios_latest_version'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
                </label>
            </div>
            <label class="inline-flex items-center gap-2 font-semibold text-rose-700"><input type="checkbox" name="ios_force_update" value="1" @checked($config['ios_force_update'] ?? false)> Force iOS Update Below Min Version</label>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
            <div class="flex items-center justify-between border-b pb-3">
                <div>
                    <h2 class="text-sm font-extrabold text-slate-900">Google Android Play Store</h2>
                    <p class="text-[11px] text-slate-500">Android smartphone &amp; tablet native client</p>
                </div>
                <label class="inline-flex items-center gap-1.5 font-semibold"><input type="checkbox" name="android_enabled" value="1" @checked($config['android_enabled'] ?? true)> Enabled</label>
            </div>
            <label class="block font-semibold">Android Application Package ID
                <input name="android_package_name" required value="{{ $config['android_package_name'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
            <label class="block font-semibold">Google Play Store URL
                <input name="android_store_url" value="{{ $config['android_store_url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block font-semibold">Min Supported Version
                    <input name="android_min_version" required value="{{ $config['android_min_version'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
                </label>
                <label class="block font-semibold">Latest Play Store Version
                    <input name="android_latest_version" required value="{{ $config['android_latest_version'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
                </label>
            </div>
            <label class="inline-flex items-center gap-2 font-semibold text-rose-700"><input type="checkbox" name="android_force_update" value="1" @checked($config['android_force_update'] ?? false)> Force Android Update Below Min Version</label>
        </div>

        <div class="md:col-span-2 bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
            <h2 class="text-sm font-extrabold text-slate-900">Global Mobile API &amp; Deep Linking</h2>
            <div class="grid md:grid-cols-3 gap-4">
                <label class="block font-semibold">Custom Deep Link URI Scheme
                    <input name="deep_link_scheme" required value="{{ $config['deep_link_scheme'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
                </label>
                <label class="block font-semibold md:col-span-2">Maintenance Mode Banner Message
                    <input name="maintenance_message" value="{{ $config['maintenance_message'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-6">
                <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="push_enabled" value="1" @checked($config['push_enabled'] ?? true)> Enable APNs (Apple) &amp; FCM (Android) Push Tokens</label>
                <label class="inline-flex items-center gap-2 font-semibold text-amber-700"><input type="checkbox" name="maintenance_mode" value="1" @checked($config['maintenance_mode'] ?? false)> Enable Mobile Maintenance Mode</label>
            </div>
            <div class="pt-2 flex flex-wrap items-center justify-between gap-3">
                <span class="text-[11px] text-slate-500 font-mono">Public API Endpoints: /api/mobile/config · /api/mobile/home · /api/mobile/login · /api/mobile/register · /api/mobile/courses</span>
                <button class="px-5 py-2.5 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Save Apple iOS &amp; Android Config</button>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-sm font-bold mb-3">Registered Apple iOS &amp; Android Devices</h2>
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b">
                <tr><th class="py-2">Platform</th><th>Device</th><th>App Version</th><th>Learner</th><th>Last Seen</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($devices as $dev)
                    <tr>
                        <td class="py-2 font-bold uppercase">{{ $dev->platform }}</td>
                        <td>{{ $dev->device_name }}</td>
                        <td class="font-mono">{{ $dev->app_version ?? '1.0.0' }}</td>
                        <td>{{ $dev->user?->name ?? 'Guest' }}</td>
                        <td>{{ $dev->last_seen_at }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-400">No iOS or Android mobile app sessions recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
