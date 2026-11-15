@extends('layouts.admin')
@section('title', 'Google Search Console & Analytics — AEC LMS')
@section('content')
<div class="space-y-6 max-w-4xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 text-[11px] font-bold">Independent Google Integration Plugin</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Google Search Console, GA4 & GTM</h1>
        <p class="text-xs text-slate-500">Verify site ownership in Google Search Console and deploy GA4 / Tag Manager scripts automatically on public routes.</p>
    </div>

    <form method="POST" action="{{ route('plugins.google_console.save') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <div>
            <label class="font-semibold text-slate-700 block mb-1">Google Search Console Verification Token (meta content)</label>
            <input name="gsc_verification_token" value="{{ $config['gsc_verification_token'] }}" placeholder="google-site-verification token value" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            <p class="text-[11px] text-slate-400 mt-1">Renders: <code>&lt;meta name="google-site-verification" content="..."&gt;</code> in public &lt;head&gt;.</p>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Google Analytics 4 (GA4) Measurement ID</label>
                <input name="ga4_measurement_id" value="{{ $config['ga4_measurement_id'] }}" placeholder="G-XXXXXXXXXX" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Google Tag Manager (GTM) Container ID</label>
                <input name="gtm_container_id" value="{{ $config['gtm_container_id'] }}" placeholder="GTM-XXXXXXX" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
        </div>

        <label class="flex items-center gap-2 font-semibold text-slate-700 cursor-pointer">
            <input type="checkbox" name="auto_inject" value="1" {{ $config['auto_inject'] ? 'checked' : '' }} class="rounded text-blue-600">
            <span>Automatically inject tags across all public LMS pages</span>
        </label>

        <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Save Google Console Settings</button>
    </form>
</div>
@endsection
