@extends('layouts.admin')
@section('title', 'SEO Engine & Sitemap — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold">Independent SEO Plugin</div>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-1">SEO Engine, XML Sitemap & Robots.txt</h1>
            <p class="text-xs text-slate-500">Manage global & route-specific Meta Tags, Open Graph snippets, canonical URLs, <code>/sitemap.xml</code>, and <code>/robots.txt</code>.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <a href="{{ url('/sitemap.xml') }}" target="_blank" class="px-3 py-2 rounded-lg border border-slate-300 bg-white font-semibold hover:bg-slate-50">View /sitemap.xml</a>
            <a href="{{ url('/robots.txt') }}" target="_blank" class="px-3 py-2 rounded-lg border border-slate-300 bg-white font-semibold hover:bg-slate-50">View /robots.txt</a>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <form method="POST" action="{{ route('plugins.seo.global') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">1. Global SEO & Robots.txt</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Default Meta Title *</label>
                <input name="site_title_seo" required value="{{ $global['site_title_seo'] }}" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Default Meta Description *</label>
                <textarea name="site_meta_description" rows="2" required class="w-full rounded-xl border border-slate-300 px-3 py-2">{{ $global['site_meta_description'] }}</textarea>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Canonical Base URL</label>
                <input name="canonical_url" value="{{ $global['canonical_url'] }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">robots.txt Content</label>
                <textarea name="robots_txt" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">{{ $global['robots_txt'] }}</textarea>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Save Global SEO</button>
        </form>

        <form method="POST" action="{{ route('plugins.seo.rules.store') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">2. Per-Page SEO Override Rule</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Route Path *</label>
                <input name="route_path" required placeholder="/courses" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">SEO Title *</label>
                <input name="meta_title" required placeholder="All Online Courses — AEC LMS" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Meta Description</label>
                <textarea name="meta_description" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Keywords</label>
                    <input name="meta_keywords" placeholder="ielts, spoken english, coaching" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Robots Directive</label>
                    <select name="robots_directive" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                        <option value="index,follow">index,follow</option>
                        <option value="noindex,follow">noindex,follow</option>
                        <option value="index,nofollow">index,nofollow</option>
                        <option value="noindex,nofollow">noindex,nofollow</option>
                    </select>
                </div>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold">Add / Update Page SEO</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Route SEO Rules ({{ $rules->count() }})</h2>
        @forelse ($rules as $rule)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-4 text-xs">
                <div>
                    <div class="font-bold text-slate-900"><span class="font-mono text-blue-600">{{ $rule->route_path }}</span> · {{ $rule->meta_title }}</div>
                    <div class="text-slate-500 mt-0.5">{{ $rule->meta_description }} ({{ $rule->robots_directive }})</div>
                </div>
                <form method="POST" action="{{ route('plugins.seo.rules.destroy', $rule) }}">
                    @csrf @method('DELETE')
                    <button class="text-rose-600 font-semibold">Delete</button>
                </form>
            </div>
        @empty
            <p class="text-xs text-slate-400">No route overrides configured yet.</p>
        @endforelse
    </div>
</div>
@endsection
