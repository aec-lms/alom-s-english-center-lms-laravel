@extends('layouts.admin')
@section('title', 'Design & Appearance — AEC LMS')
@section('content')
@php
    $labels = [
        'primary' => 'Primary', 'primary_hover' => 'Primary Hover', 'secondary' => 'Secondary',
        'accent' => 'Accent', 'page_bg' => 'Page Background', 'surface' => 'Card / Surface',
        'surface_alt' => 'Soft Surface', 'text' => 'Main Text', 'muted' => 'Muted Text',
        'border' => 'Border', 'sidebar_bg' => 'Sidebar Background', 'sidebar_text' => 'Sidebar Text',
        'header_bg' => 'Header Background', 'success' => 'Success', 'warning' => 'Warning',
        'danger' => 'Danger / Error', 'info' => 'Information', 'link' => 'Links',
        'button_text' => 'Button Text', 'input_bg' => 'Input Background', 'input_text' => 'Input Text',
    ];
@endphp
<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Design &amp; Appearance</h1>
            <p class="mt-1 text-sm text-slate-500">Change LMS Core, admin panel, public website and portal colors without coding.</p>
        </div>
        <a href="{{ route('admin.appearance.themes.index') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:border-blue-300">Theme Package Manager →</a>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div>
            <label for="theme-scope" class="block text-sm font-bold text-slate-700 mb-2">Where should this theme apply?</label>
            <select id="theme-scope" class="w-full max-w-md rounded-xl border border-slate-300 px-3 py-2.5 text-sm" onchange="window.location=this.value">
                <option value="{{ route('admin.appearance.index', ['scope' => 'global']) }}" @selected($scope === 'global')>Global — LMS Core + All Plugins + Website + Portals</option>
                @foreach ($plugins as $plugin)
                    <option value="{{ route('admin.appearance.index', ['scope' => 'plugin.'.$plugin->slug]) }}" @selected($scope === 'plugin.'.$plugin->slug)>Only plugin: {{ $plugin->name }}</option>
                @endforeach
            </select>
            <p class="mt-2 text-xs text-slate-500">A plugin-specific theme overrides Global colors only inside that plugin. Core and other plugins keep the Global theme.</p>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.appearance.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="scope" value="{{ $scope }}">
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-5">
                <div>
                    <h2 class="font-extrabold text-slate-900">{{ $scope === 'global' ? 'Global Theme Palette' : 'Plugin Theme Palette' }}</h2>
                    <p class="text-xs text-slate-500 mt-1">Choose any color. Changes preview instantly and apply after saving.</p>
                </div>
                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{{ $scope === 'global' ? 'Global' : str_replace('plugin.', '', $scope) }}</span>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($labels as $key => $label)
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 hover:border-blue-300">
                        <input type="color" name="colors[{{ $key }}]" value="{{ $colors[$key] }}" data-theme-color="{{ $key }}" class="h-11 w-12 cursor-pointer rounded-lg border-0 bg-transparent">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-slate-800">{{ $label }}</span>
                            <span class="block text-xs font-mono text-slate-500" data-color-value="{{ $key }}">{{ $colors[$key] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
            <h2 class="font-extrabold text-slate-900">Live Preview</h2>
            <div id="theme-preview" class="rounded-2xl border p-5 space-y-4" style="background:{{ $colors['page_bg'] }};color:{{ $colors['text'] }};border-color:{{ $colors['border'] }}">
                <div class="flex items-center justify-between rounded-xl p-4" style="background:{{ $colors['sidebar_bg'] }};color:{{ $colors['sidebar_text'] }}">
                    <strong>AEC LMS Core</strong><span class="text-xs">Admin / Student / Teacher</span>
                </div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <div class="rounded-xl border p-4" style="background:{{ $colors['surface'] }};border-color:{{ $colors['border'] }}"><div class="text-xs" style="color:{{ $colors['muted'] }}">Dashboard Card</div><strong class="text-xl">1,248</strong></div>
                    <div class="rounded-xl border p-4" style="background:{{ $colors['surface_alt'] }};border-color:{{ $colors['border'] }}">Soft Surface</div>
                    <div class="rounded-xl border p-4" style="background:{{ $colors['input_bg'] }};border-color:{{ $colors['border'] }};color:{{ $colors['input_text'] }}">Form Input</div>
                </div>
                <div class="flex flex-wrap gap-2 items-center">
                    <button type="button" class="rounded-lg px-4 py-2 font-bold" style="background:{{ $colors['primary'] }};color:{{ $colors['button_text'] }}">Primary Button</button>
                    <span class="rounded-lg px-3 py-2 text-sm font-bold" style="background:{{ $colors['success'] }};color:#fff">Success</span>
                    <span class="rounded-lg px-3 py-2 text-sm font-bold" style="background:{{ $colors['warning'] }};color:#fff">Warning</span>
                    <span class="rounded-lg px-3 py-2 text-sm font-bold" style="background:{{ $colors['danger'] }};color:#fff">Danger</span>
                    <a href="#" style="color:{{ $colors['link'] }}">Sample link</a>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="rounded-xl px-5 py-3 text-sm font-bold text-white shadow-sm" style="background:#0057FF">Save Theme</button>
        </div>
    </form>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div>
            <h2 class="font-extrabold text-slate-900">Global Typography &amp; Layout Controls</h2>
            <p class="mt-1 text-xs text-slate-500">Set safe site-wide font, text scale, content width, corner radius and spacing. Plugin scopes inherit global values unless overridden.</p>
        </div>
        <form method="POST" action="{{ route('admin.appearance.design') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @csrf
            <input type="hidden" name="scope" value="{{ $scope }}">
            <label class="block text-sm font-semibold text-slate-700">Font family
                <select name="design[font_family]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="plus-jakarta" @selected($design['font_family'] === 'plus-jakarta')>Plus Jakarta Sans + Bengali fallback</option>
                    <option value="inter" @selected($design['font_family'] === 'inter')>Inter + Bengali fallback</option>
                    <option value="noto-sans" @selected($design['font_family'] === 'noto-sans')>Noto Sans Bengali</option>
                    <option value="system" @selected($design['font_family'] === 'system')>System default</option>
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Base font size
                <select name="design[font_size]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    @foreach (['14','15','16','17','18'] as $size)<option value="{{ $size }}" @selected($design['font_size'] === $size)>{{ $size }} px</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Heading weight
                <select name="design[heading_weight]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    @foreach (['600','700','800','900'] as $weight)<option value="{{ $weight }}" @selected($design['heading_weight'] === $weight)>{{ $weight }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Content width
                <select name="design[content_width]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    @foreach (['960','1120','1280','1440','1600'] as $width)<option value="{{ $width }}" @selected($design['content_width'] === $width)>{{ $width }} px</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Corner radius
                <select name="design[border_radius]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    @foreach (['8','12','16','20','24'] as $radius)<option value="{{ $radius }}" @selected($design['border_radius'] === $radius)>{{ $radius }} px</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Interface density
                <select name="design[density]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="comfortable" @selected($design['density'] === 'comfortable')>Comfortable</option>
                    <option value="compact" @selected($design['density'] === 'compact')>Compact</option>
                </select>
            </label>
            <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">Save Typography &amp; Layout</button>
            </div>
        </form>
    </section>

    <form method="POST" action="{{ route('admin.appearance.reset') }}" onsubmit="return confirm('Reset this theme to inherited/default colors?')">
        @csrf
        <input type="hidden" name="scope" value="{{ $scope }}">
        <button type="submit" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Reset to Default / Global</button>
    </form>
</div>
<script>
(function () {
    const inputs = document.querySelectorAll('[data-theme-color]');
    const preview = document.getElementById('theme-preview');
    const set = (key, value) => {
        const input = document.querySelector('[data-theme-color="' + key + '"]');
        if (input) input.value = value;
        const label = document.querySelector('[data-color-value="' + key + '"]');
        if (label) label.textContent = value.toUpperCase();
        const cssVariable = '--aec-' + key.replaceAll('_', '-');
        document.documentElement.style.setProperty(cssVariable, value);
        const map = {
            page_bg: ['backgroundColor'], text: ['color'], border: ['borderColor'],
            sidebar_bg: ['--preview-sidebar-bg'], sidebar_text: ['--preview-sidebar-text'],
            surface: ['--preview-surface'], surface_alt: ['--preview-surface-alt'],
            input_bg: ['--preview-input-bg'], input_text: ['--preview-input-text'],
            primary: ['--preview-primary'], button_text: ['--preview-button-text'],
            success: ['--preview-success'], warning: ['--preview-warning'], danger: ['--preview-danger'], link: ['--preview-link']
        };
        if (map[key]) map[key].forEach(prop => prop.startsWith('--') ? preview.style.setProperty(prop, value) : preview.style[prop] = value);
        const sidebar = preview.querySelector('div');
        if (key === 'sidebar_bg') sidebar.style.backgroundColor = value;
        if (key === 'sidebar_text') sidebar.style.color = value;
        if (key === 'primary') preview.querySelector('button').style.backgroundColor = value;
        if (key === 'button_text') preview.querySelector('button').style.color = value;
        if (key === 'success') preview.querySelectorAll('span')[1].style.backgroundColor = value;
        if (key === 'warning') preview.querySelectorAll('span')[2].style.backgroundColor = value;
        if (key === 'danger') preview.querySelectorAll('span')[3].style.backgroundColor = value;
        if (key === 'link') preview.querySelector('a').style.color = value;
        if (key === 'surface') preview.querySelectorAll('.grid > div')[0].style.backgroundColor = value;
        if (key === 'surface_alt') preview.querySelectorAll('.grid > div')[1].style.backgroundColor = value;
        if (key === 'input_bg') preview.querySelectorAll('.grid > div')[2].style.backgroundColor = value;
        if (key === 'input_text') preview.querySelectorAll('.grid > div')[2].style.color = value;
        if (key === 'muted') preview.querySelector('.grid > div div').style.color = value;
    };
    inputs.forEach(input => input.addEventListener('input', () => set(input.dataset.themeColor, input.value)));
})();
</script>
@endsection
