@extends('layouts.admin')
@section('title', 'Dashboard — AEC LMS')
@section('content')
@php
    $widgetLabels = [
        'users' => 'Registered Accounts',
        'plugins' => 'Plugin Overview',
        'backups' => 'Encrypted Backups',
        'audit_trail' => 'Security Audit Trail',
        'system_info' => 'System Information',
    ];
    foreach (($pluginWidgets ?? []) as $key => $definition) {
        $widgetLabels[$key] = $definition['label'];
    }
    if (auth()->user()?->hasRole('branch_manager')) {
        $widgetLabels = array_intersect_key($widgetLabels, ['users' => true]);
    }
@endphp
<div class="space-y-6">
    @if($setupIncomplete)
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950" role="status">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="font-bold">আপনার LMS Setup এখনো সম্পন্ন হয়নি</h2><p class="mt-1 text-sm">প্রতিষ্ঠান, ব্র্যান্ডিং, শিক্ষাবর্ষ ও পেমেন্ট সেটআপ শেষ করুন। সংরক্ষিত অগ্রগতি পরে চালিয়ে যেতে পারবেন।</p></div>
                @can('core.settings.manage')
                    <a href="{{ route('admin.setup-wizard.index') }}" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-bold text-white">Setup Wizard চালু করুন</a>
                @endcan
            </div>
        </section>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Core System Overview</h1>
            <p class="text-xs text-slate-500">Your dashboard layout is saved per administrator account.</p>
        </div>
        @can('core.settings.manage')
            <details class="relative">
                <summary class="cursor-pointer rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm">Motion &amp; Loading</summary>
                <form method="POST" action="{{ route('admin.dashboard.motion') }}" class="absolute right-0 z-30 mt-2 grid w-[min(92vw,24rem)] gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
                    @csrf
                    <div><h2 class="text-sm font-bold text-slate-900">Dashboard experience</h2><p class="text-xs text-slate-500">Global settings for supported dashboard widgets.</p></div>
                    <label class="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">Enable motion
                        <input type="checkbox" name="enabled" value="1" @checked($motionSettings['enabled']) class="rounded border-slate-300">
                    </label>
                    <label class="grid gap-1 text-xs font-semibold text-slate-600">Motion preset
                        <select name="preset" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="subtle" @selected($motionSettings['preset'] === 'subtle')>Subtle</option><option value="premium" @selected($motionSettings['preset'] === 'premium')>Premium</option><option value="dynamic" @selected($motionSettings['preset'] === 'dynamic')>Dynamic</option></select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="grid gap-1 text-xs font-semibold text-slate-600">Speed
                            <select name="speed" class="rounded-lg border border-slate-300 px-2 py-2 text-sm"><option value="slow" @selected($motionSettings['speed'] === 'slow')>Slow</option><option value="normal" @selected($motionSettings['speed'] === 'normal')>Normal</option><option value="fast" @selected($motionSettings['speed'] === 'fast')>Fast</option></select>
                        </label>
                        <label class="grid gap-1 text-xs font-semibold text-slate-600">Intensity
                            <select name="intensity" class="rounded-lg border border-slate-300 px-2 py-2 text-sm"><option value="low" @selected($motionSettings['intensity'] === 'low')>Low</option><option value="medium" @selected($motionSettings['intensity'] === 'medium')>Medium</option><option value="high" @selected($motionSettings['intensity'] === 'high')>High</option></select>
                        </label>
                    </div>
                    <label class="grid gap-1 text-xs font-semibold text-slate-600">Loading indicator
                        <select name="loading" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="shimmer" @selected($motionSettings['loading'] === 'shimmer')>Premium shimmer</option><option value="minimal" @selected($motionSettings['loading'] === 'minimal')>Minimal progress</option><option value="none" @selected($motionSettings['loading'] === 'none')>Off</option></select>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="counters" value="1" @checked($motionSettings['counters']) class="rounded border-slate-300"> Animate statistic counters</label>
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="hover" value="1" @checked($motionSettings['hover']) class="rounded border-slate-300"> Enable card hover effects</label>
                    <p class="text-[11px] leading-relaxed text-slate-500">System-level Reduced Motion preferences always override animation. Settings apply globally to supported dashboards.</p>
                    <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white">Save experience settings</button>
                </form>
            </details>
        @endcan
        <details class="relative">
            <summary class="cursor-pointer rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm">Customize Dashboard</summary>
            <form method="POST" action="{{ route('admin.dashboard.widgets') }}" class="absolute right-0 z-20 mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
                @csrf
                <h2 class="mb-1 text-sm font-bold text-slate-900">Arrange dashboard widgets</h2>
                <p class="mb-3 text-[11px] text-slate-500">Drag rows or use the arrows. Uncheck widgets you want to hide.</p>
                <ol id="dashboard-widget-order" class="space-y-2">
                    @foreach ($widgetLabels as $key => $label)
                        @if (!$isBranchManager || $key === 'users')
                            <li draggable="true" data-widget-row class="flex items-center gap-2 rounded-lg border border-slate-200 p-2 cursor-move">
                                <span aria-hidden="true" class="text-slate-400">⠿</span>
                                <label class="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="widgets[]" value="{{ $key }}" @checked(in_array($key, $widgets, true)) class="rounded border-slate-300">
                                    <span>{{ $label }}</span>
                                </label>
                                <button type="button" data-move="up" aria-label="Move {{ $label }} up" class="rounded border px-2 py-1 text-xs">↑</button>
                                <button type="button" data-move="down" aria-label="Move {{ $label }} down" class="rounded border px-2 py-1 text-xs">↓</button>
                            </li>
                        @endif
                    @endforeach
                </ol>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" id="dashboard-reset-order" class="text-xs font-semibold text-slate-500">Reset default</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white">Save layout</button>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">Order and visibility apply only to your account.</p>
            </form>
        </details>
    </div>

    <style>
    #dashboard-widgets[data-motion-enabled="true"] [data-dashboard-widget] { animation: aec-dashboard-enter var(--aec-motion-duration, 620ms) cubic-bezier(.2,.8,.2,1) both; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-preset="subtle"] [data-dashboard-widget] { animation-name: aec-dashboard-fade; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-preset="dynamic"] [data-dashboard-widget] { animation-name: aec-dashboard-rise; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-intensity="low"] [data-dashboard-widget] { animation-duration: 420ms; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-intensity="high"] [data-dashboard-widget] { animation-duration: 820ms; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-speed="slow"] { --aec-motion-duration: 900ms; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-speed="fast"] { --aec-motion-duration: 360ms; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-speed="normal"] { --aec-motion-duration: 620ms; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-hover="true"] [data-dashboard-widget] { transition: transform 220ms ease, box-shadow 220ms ease, border-color 220ms ease; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-hover="true"] [data-dashboard-widget]:hover { transform: translateY(-3px); box-shadow: 0 16px 36px rgba(15,23,42,.09); border-color: rgba(100,116,139,.38); }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-loading="shimmer"]::before { content:""; display:block; height:3px; border-radius:999px; background:linear-gradient(90deg,transparent,#60a5fa,#a78bfa,transparent); background-size:200% 100%; animation:aec-dashboard-shimmer 1.6s ease-in-out 1; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-loading="minimal"]::before { content:""; display:block; height:2px; border-radius:999px; background:var(--aec-primary); transform-origin:left; animation:aec-dashboard-progress 650ms ease-out 1 both; }
    @keyframes aec-dashboard-enter { from { opacity:0; transform:translateY(14px) scale(.99); } to { opacity:1; transform:translateY(0) scale(1); } }
    @keyframes aec-dashboard-fade { from { opacity:0; } to { opacity:1; } }
    @keyframes aec-dashboard-rise { from { opacity:0; transform:translateY(24px) scale(.985); } to { opacity:1; transform:translateY(0) scale(1); } }
    @keyframes aec-dashboard-shimmer { from { background-position:100% 0; opacity:.5; } to { background-position:-100% 0; opacity:1; } }
    @keyframes aec-dashboard-progress { from { transform:scaleX(.15); opacity:.45; } to { transform:scaleX(1); opacity:1; } }
    @media (prefers-reduced-motion: reduce) {
        #dashboard-widgets[data-motion-enabled="true"] [data-dashboard-widget],
        #dashboard-widgets[data-motion-enabled="true"][data-motion-hover="true"] [data-dashboard-widget],
        #dashboard-widgets[data-motion-enabled="true"][data-motion-loading]::before { animation:none !important; transition:none !important; transform:none !important; }
    }
</style>
<div id="dashboard-widgets" class="grid gap-4" data-motion-enabled="{{ $motionSettings['enabled'] ? 'true' : 'false' }}" data-motion-preset="{{ $motionSettings['preset'] }}" data-motion-speed="{{ $motionSettings['speed'] }}" data-motion-intensity="{{ $motionSettings['intensity'] }}" data-motion-loading="{{ $motionSettings['loading'] }}" data-motion-counters="{{ $motionSettings['counters'] ? 'true' : 'false' }}" data-motion-hover="{{ $motionSettings['hover'] ? 'true' : 'false' }}">
        @foreach ($widgets as $widget)
            @if ($widget === 'users')
                <section data-dashboard-widget="users" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Registered Accounts</div>
                    <div class="mt-1 text-2xl font-extrabold"><span data-count-to="{{ $metrics['users'] }}">{{ $metrics['users'] }}</span></div>
                </section>
            @elseif ($widget === 'plugins')
                <section data-dashboard-widget="plugins" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-sm font-bold text-slate-900">Plugin Overview</h2><span class="text-xs text-slate-500">{{ $metrics['plugins_total'] }} discovered</span></div>
                    <div class="mt-2 text-2xl font-extrabold text-emerald-700"><span data-count-to="{{ $metrics['plugins_enabled'] }}">{{ $metrics['plugins_enabled'] }}</span> <span class="text-sm font-medium text-slate-500">active</span></div>
                </section>
            @elseif ($widget === 'backups')
                <section data-dashboard-widget="backups" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Encrypted Backups</div>
                    <div class="mt-1 text-2xl font-extrabold"><span data-count-to="{{ $metrics['backups'] }}">{{ $metrics['backups'] }}</span></div>
                </section>
            @elseif ($widget === 'system_info')
                <section data-dashboard-widget="system_info" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-bold text-slate-900">System Information</h2>
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><span class="text-slate-500">Core version:</span> <strong>{{ $metrics['core_version'] }}</strong></div>
                        <div><span class="text-slate-500">PHP runtime:</span> <strong>{{ $metrics['php_version'] }}</strong></div>
                    </div>
                </section>
            @elseif ($widget === 'audit_trail')
                <section data-dashboard-widget="audit_trail" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-bold text-slate-900">Recent Security Audit Trail</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b text-slate-500"><tr><th class="py-2">Action</th><th>Entity</th><th>Actor</th><th>Time</th></tr></thead>
                            <tbody class="divide-y">
                                @forelse ($recentAudits as $log)
                                    <tr><td class="py-2 font-mono">{{ $log->action }}</td><td>{{ $log->entity_type }}:{{ $log->entity_id }}</td><td>{{ $log->user?->email ?? 'system' }}</td><td>{{ $log->created_at }}</td></tr>
                                @empty
                                    <tr><td colspan="4" class="py-4 text-slate-400">No audit events recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @else
                @php($pluginWidget = $pluginWidgets[$widget] ?? null)
                @if ($pluginWidget)
                    <section data-dashboard-widget="{{ $widget }}" class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><h2 class="text-sm font-bold text-slate-900">{{ $pluginWidget['label'] }}</h2><p class="mt-1 text-xs text-slate-500">{{ $pluginWidget['plugin'] }}</p>@if ($pluginWidget['description'] !== '')<p class="mt-2 text-sm text-slate-600">{{ $pluginWidget['description'] }}</p>@endif</div>
                            @if ($pluginWidget['route'])<a class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50" href="{{ route($pluginWidget['route']) }}">Open module</a>@endif
                        </div>
                    </section>
                @endif
            @endif
        @endforeach
        @if (count($widgets) === 0)
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">All dashboard widgets are hidden. Use Customize Dashboard to add widgets.</div>
        @endif
    </div>
</div>
<script>
(() => {
    const dashboard = document.getElementById('dashboard-widgets');
    if (dashboard?.dataset.motionEnabled === 'true' && dashboard.dataset.motionCounters === 'true' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const duration = dashboard.dataset.motionSpeed === 'fast' ? 520 : dashboard.dataset.motionSpeed === 'slow' ? 1250 : 850;
        dashboard.querySelectorAll('[data-count-to]').forEach((node, index) => {
            const target = Number(node.dataset.countTo);
            if (!Number.isFinite(target) || target < 0 || target > 1000000000) return;
            const start = performance.now() + index * 90;
            const tick = now => {
                const progress = Math.min(1, Math.max(0, (now - start) / duration));
                const eased = 1 - Math.pow(1 - progress, 3);
                node.textContent = Math.round(target * eased).toLocaleString();
                if (progress < 1) requestAnimationFrame(tick);
            };
            node.textContent = '0';
            requestAnimationFrame(tick);
        });
    }
    const list = document.getElementById('dashboard-widget-order');
    if (!list) return;
    let dragged = null;
    const rows = () => Array.from(list.querySelectorAll('[data-widget-row]'));
    list.addEventListener('dragstart', event => {
        const row = event.target.closest('[data-widget-row]');
        if (!row) return;
        dragged = row;
        event.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragover', event => {
        if (!dragged) return;
        event.preventDefault();
        const target = event.target.closest('[data-widget-row]');
        if (target && target !== dragged) {
            const rect = target.getBoundingClientRect();
            list.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
        }
    });
    list.addEventListener('dragend', () => { dragged = null; });
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-move]');
        if (!button) return;
        const row = button.closest('[data-widget-row]');
        if (button.dataset.move === 'up' && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
        if (button.dataset.move === 'down' && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
    });
    document.getElementById('dashboard-reset-order')?.addEventListener('click', () => {
        rows().forEach(row => {
            list.appendChild(row);
            row.querySelector('input[type=checkbox]').checked = true;
        });
    });
})();
</script>
@endsection
