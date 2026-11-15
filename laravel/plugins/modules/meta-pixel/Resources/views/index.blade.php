@extends('layouts.admin')
@section('title', 'Facebook Meta Pixel — AEC LMS')
@section('content')
@php
    $enabledEvents = array_filter(array_map('trim', explode(',', $config['track_events'] ?? 'PageView')));
@endphp
<div class="space-y-6 max-w-4xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold">Independent Marketing Plugin</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Facebook Meta Pixel & Conversions API</h1>
        <p class="text-xs text-slate-500">Injects Meta Pixel automatically across public landing, course, and checkout pages when enabled.</p>
    </div>

    <form method="POST" action="{{ route('plugins.meta_pixel.save') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Meta Pixel ID *</label>
                <input name="pixel_id" value="{{ $config['pixel_id'] }}" placeholder="984029104829103" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Test Event Code (Optional)</label>
                <input name="test_event_code" value="{{ $config['test_event_code'] }}" placeholder="TEST12345" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
        </div>

        <div>
            <label class="font-semibold text-slate-700 block mb-1">
                Conversions API Access Token (Encrypted)
                @if ($config['has_capi_token'])
                    <span class="text-emerald-700 font-bold ml-1">· Configured</span>
                @endif
            </label>
            <input type="password" name="capi_access_token" placeholder="{{ $config['has_capi_token'] ? 'Leave blank to keep existing secret token' : 'EAAG...' }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
        </div>

        <div>
            <label class="font-semibold text-slate-700 block mb-2">Tracked Conversion Events</label>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                @foreach (['PageView', 'ViewContent', 'Lead', 'InitiateCheckout', 'Purchase'] as $ev)
                    <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-50 border border-slate-200 cursor-pointer">
                        <input type="checkbox" name="events[]" value="{{ $ev }}" {{ in_array($ev, $enabledEvents, true) ? 'checked' : '' }} class="rounded text-blue-600">
                        <span class="font-semibold">{{ $ev }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Save Meta Pixel Settings</button>
    </form>
</div>
@endsection
