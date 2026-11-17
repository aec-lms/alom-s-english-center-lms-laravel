@extends('layouts.admin')
@section('title', 'Theme Preview — '.$theme['name'])
@section('content')
@php
    $defaults = \App\Core\Appearance\ThemeManager::DEFAULTS;
    $colors = array_merge($defaults, $theme['colors']);
    $design = array_merge(\App\Core\Appearance\ThemeManager::DESIGN_DEFAULTS, $theme['design']);
    $fontStacks = [
        'plus-jakarta' => "'Plus Jakarta Sans', 'Noto Sans Bengali', sans-serif",
        'inter' => "Inter, 'Noto Sans Bengali', sans-serif",
        'noto-sans' => "'Noto Sans Bengali', 'Noto Sans', sans-serif",
        'system' => "system-ui, 'Noto Sans Bengali', sans-serif",
    ];
@endphp
<div class="mx-auto max-w-6xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio · Read-only preview</p>
            <h1 class="mt-1 text-2xl font-extrabold text-slate-900">{{ $theme['name'] }}</h1>
            <p class="mt-1 text-sm text-slate-500">Version {{ $theme['version'] }} · {{ $theme['description'] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.appearance.themes.index') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold">Back to themes</a>
            <form method="POST" action="{{ route('admin.appearance.themes.activate', $theme['slug']) }}">
                @csrf
                <button class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">Activate this theme</button>
            </form>
        </div>
    </header>
    <div role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Preview only: your current LMS appearance and active theme are not changed until you select “Activate this theme”.
    </div>
    <section class="overflow-hidden rounded-2xl border shadow-sm" style="background:{{ $colors['page_bg'] }};color:{{ $colors['text'] }};border-color:{{ $colors['border'] }};font-family:{{ $fontStacks[$design['font_family']] }};font-size:{{ $design['font_size'] }}px">
        <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-5" style="background:{{ $colors['header_bg'] }};border-bottom:1px solid {{ $colors['border'] }}">
            <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl font-black text-white" style="background:{{ $colors['primary'] }}">A</span><div><strong class="block">AEC Learning Center</strong><span class="text-xs" style="color:{{ $colors['muted'] }}">Learn · Practice · Grow</span></div></div>
            <nav class="flex flex-wrap gap-4 text-sm font-semibold"><span>Home</span><span>Courses</span><span>Teachers</span><span>About</span></nav>
            <span class="rounded-lg px-4 py-2 text-sm font-bold" style="background:{{ $colors['primary'] }};color:{{ $colors['button_text'] }}">Student Login</span>
        </div>
        <div class="mx-auto max-w-5xl space-y-6 px-6 py-10" style="max-width:{{ $design['content_width'] }}px">
            <div class="rounded-2xl p-8 md:p-12" style="background:linear-gradient(120deg,{{ $colors['secondary'] }},{{ $colors['primary'] }});border-radius:{{ $design['border_radius'] }}px;color:#fff">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:{{ $colors['accent'] }}">Your learning journey starts here</p>
                <h2 class="mt-3 text-3xl font-black md:text-4xl" style="font-weight:{{ $design['heading_weight'] }}">Build confidence in English</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-white/85">Explore courses, learn from experienced instructors and make progress at your own pace.</p>
                <span class="mt-6 inline-flex rounded-lg px-5 py-3 text-sm font-bold" style="background:{{ $colors['accent'] }};color:{{ $colors['secondary'] }}">Explore Courses</span>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ([['Popular Courses','Structured lessons for every level'],['Expert Teachers','Learn with experienced instructors'],['Flexible Learning','Study online and in your own time']] as $card)
                    <article class="rounded-xl border p-5" style="background:{{ $colors['surface'] }};border-color:{{ $colors['border'] }};border-radius:{{ $design['border_radius'] }}px">
                        <div class="mb-3 h-2 w-12 rounded-full" style="background:{{ $colors['accent'] }}"></div>
                        <h3 class="font-bold" style="font-weight:{{ $design['heading_weight'] }}">{{ $card[0] }}</h3>
                        <p class="mt-2 text-sm" style="color:{{ $colors['muted'] }}">{{ $card[1] }}</p>
                        <a href="#" class="mt-4 inline-block text-sm font-bold" style="color:{{ $colors['link'] }}">Learn more →</a>
                    </article>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3 rounded-xl p-5" style="background:{{ $colors['surface_alt'] }}">
                <span class="rounded-lg px-3 py-2 text-xs font-bold text-white" style="background:{{ $colors['success'] }}">Success</span>
                <span class="rounded-lg px-3 py-2 text-xs font-bold text-white" style="background:{{ $colors['warning'] }}">Notice</span>
                <span class="rounded-lg px-3 py-2 text-xs font-bold text-white" style="background:{{ $colors['danger'] }}">Alert</span>
                <span class="text-sm" style="color:{{ $colors['muted'] }}">Sample status and interface colors</span>
            </div>
        </div>
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-bold text-slate-900">Included design tokens</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($theme['colors'] as $key => $value)
                <span class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs"><span class="h-4 w-4 rounded" style="background:{{ $value }}"></span>{{ str_replace('_',' ',$key) }} <code>{{ $value }}</code></span>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-slate-500">Font: {{ $design['font_family'] }} · Base size: {{ $design['font_size'] }}px · Content width: {{ $design['content_width'] }}px · Radius: {{ $design['border_radius'] }}px · Density: {{ $design['density'] }}</p>
    </section>
</div>
@endsection
