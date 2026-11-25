@extends('layouts.admin')
@section('title','Theme Package Manager — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio</p><h1 class="mt-1 text-2xl font-extrabold text-slate-900">Theme Package Manager</h1><p class="mt-1 max-w-2xl text-sm text-slate-500">Upload, install, activate and remove signed-off design-token theme packages. Packages cannot execute PHP, Blade, JavaScript or CSS.</p></div>
        <a href="{{ route('admin.appearance.index') }}" class="rounded-xl border bg-white px-4 py-2.5 text-sm font-semibold">Back to Appearance</a>
    </header>
    @if(session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><strong>Theme package could not be processed.</strong><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div><h2 class="text-lg font-extrabold text-slate-900">Upload Theme ZIP</h2><p class="mt-1 text-xs leading-5 text-slate-500">A package must contain only a root-level theme.json file. Only validated color and typography tokens are applied.</p></div>
        <form method="POST" action="{{ route('admin.appearance.themes.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
            @csrf
            <input type="file" name="theme_zip" accept=".zip,application/zip" required class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">Upload &amp; Install Theme</button>
        </form>
        <div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
            <strong>Package manifest example:</strong>
            <pre class="mt-2 overflow-x-auto text-[11px] leading-5">{
  "slug": "ocean-blue",
  "name": "Ocean Blue",
  "version": "1.0.0",
  "author": "Aloms English Center",
  "description": "A clean blue learning theme",
  "colors": { "primary": "#075985", "accent": "#38BDF8" },
  "design": { "font_family": "plus-jakarta", "content_width": "1280" }
}</pre>
        </div>
    </section>
    <section class="space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-extrabold text-slate-900">Installed Themes ({{ count($themes) }})</h2><span class="text-xs text-slate-500">Private packages · no executable code</span></div>
        @forelse($themes as $theme)
            <article class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-900">{{ $theme['name'] }}</h3>@if($activeSlug===$theme['slug'])<span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800">Active Theme</span>@endif</div>
                    <p class="mt-1 text-sm text-slate-500">{{ $theme['description'] }}</p>
                    <p class="mt-1 text-xs text-slate-400">Slug: {{ $theme['slug'] }} · Version {{ $theme['version'] }} · By {{ $theme['author'] }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.appearance.themes.preview',$theme['slug']) }}" class="rounded-lg border border-blue-200 px-3 py-2 text-xs font-bold text-blue-700">Preview</a>
                    @if(is_file(storage_path('app/aec-themes/'.$theme['slug'].'/versions/previous.json')))
                        <form method="POST" action="{{ route('admin.appearance.themes.rollback',$theme['slug']) }}" onsubmit="return confirm('Roll back to the previous installed theme version?')">@csrf<button class="rounded-lg border border-amber-200 px-3 py-2 text-xs font-bold text-amber-800">Rollback Version</button></form>
                    @endif
                    @if($activeSlug===$theme['slug'])
                        <form method="POST" action="{{ route('admin.appearance.themes.deactivate') }}">@csrf<button class="rounded-lg border border-amber-200 px-3 py-2 text-xs font-bold text-amber-800">Deactivate</button></form>
                    @else
                        <form method="POST" action="{{ route('admin.appearance.themes.activate',$theme['slug']) }}">@csrf<button class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white">Activate</button></form>
                        <form method="POST" action="{{ route('admin.appearance.themes.uninstall',$theme['slug']) }}" onsubmit="return confirm('Uninstall this theme package?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700">Uninstall</button></form>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">No theme packages installed. Upload a theme ZIP to get started.</div>
        @endforelse
    </section>
</div>
@endsection
