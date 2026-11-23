@extends('layouts.admin')
@section('title', 'Dashboard — AEC LMS')
@section('content')
@php
    $widgetLabels = collect($widgetDefinitions)->mapWithKeys(
        fn (array $definition, string $key): array => [$key => $definition['label']]
    )->all();
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
    <section class="aec-edupro-hero" aria-labelledby="aec-dashboard-welcome">
        <div class="aec-edupro-hero__glow" aria-hidden="true"></div>
        <div class="aec-edupro-hero__content">
            <div>
                <p class="aec-edupro-hero__eyebrow">ALOM'S ENGLISH CENTER · ADMIN WORKSPACE</p>
                <h2 id="aec-dashboard-welcome">Welcome back, {{ auth()->user()?->name ?? 'Administrator' }} <span aria-hidden="true">✦</span></h2>
                <p>Your learning platform at a glance. Review operations, activity and system health from one place.</p>
            </div>
            <div class="aec-edupro-hero__date">
                <span aria-hidden="true">◷</span>
                <span>{{ now()->format('l, d F Y') }}</span>
            </div>
        </div>
        <div class="aec-edupro-hero__orb aec-edupro-hero__orb--one" aria-hidden="true"></div>
        <div class="aec-edupro-hero__orb aec-edupro-hero__orb--two" aria-hidden="true"></div>
    </section>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Dashboard</h1>
            <p class="text-xs text-slate-500">Manage your LMS operations, users, plugins and system health from one workspace.</p>
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
                <label class="mb-3 grid gap-1 text-xs font-semibold text-slate-600">Dashboard preset
                    <select id="dashboard-preset" class="rounded-lg border border-slate-300 px-2 py-2 text-sm">
                        <option value="role">Role default</option><option value="overview">Executive overview</option><option value="operations">Operations &amp; security</option><option value="teaching">Teaching &amp; learning</option><option value="finance">Finance &amp; records</option>
                    </select>
                </label>
                <p class="mb-3 text-[11px] text-slate-500">Drag rows or use the arrows. Uncheck widgets you want to hide. Reset restores the original default selection.</p>
                <ol id="dashboard-widget-order" class="space-y-2">
                    @foreach ($widgetLabels as $key => $label)
                        @if (!$isBranchManager || $key === 'users')
                            <li draggable="true" data-widget-row data-default-enabled="{{ in_array($key, $roleDefaultWidgets, true) ? 'true' : 'false' }}" class="flex items-center gap-2 rounded-lg border border-slate-200 p-2 cursor-move">
                                <span aria-hidden="true" class="text-slate-400">⠿</span>
                                <label class="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="widgets[]" value="{{ $key }}" @checked(in_array($key, $widgets, true)) class="rounded border-slate-300">
                                    <span>{{ $label }}</span>
                                    <span class="ml-auto text-[10px] text-slate-400" title="Views in this account">Views: {{ number_format($widgetAnalytics[$key] ?? 0) }}</span>
                                </label>
                                <label class="text-[10px] text-slate-500">Size<select name="sizes[{{ $key }}]" class="ml-1 rounded border border-slate-200 px-1 py-1 text-xs"><option value="small" @selected(($widgetSizes[$key] ?? "medium") === "small")>Small</option><option value="medium" @selected(($widgetSizes[$key] ?? "medium") === "medium")>Medium</option><option value="large" @selected(($widgetSizes[$key] ?? "medium") === "large")>Large</option></select></label>
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
    #dashboard-widgets [data-dashboard-widget][data-size="small"] { min-height: 7rem; }
    @media (min-width: 768px) {
        #dashboard-widgets [data-dashboard-widget][data-size="small"] { grid-column: span 1; }
        #dashboard-widgets [data-dashboard-widget][data-size="medium"] { grid-column: span 2; }
        #dashboard-widgets [data-dashboard-widget][data-size="large"] { grid-column: span 4; }
    }
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
<div id="dashboard-widgets" class="grid grid-cols-1 gap-4 md:grid-cols-4" data-refresh-url="{{ route('admin.dashboard.refresh') }}" data-analytics-url="{{ route('admin.dashboard.widget-analytics') }}" data-motion-enabled="{{ $motionSettings['enabled'] ? 'true' : 'false' }}" data-motion-preset="{{ $motionSettings['preset'] }}" data-motion-speed="{{ $motionSettings['speed'] }}" data-motion-intensity="{{ $motionSettings['intensity'] }}" data-motion-loading="{{ $motionSettings['loading'] }}" data-motion-counters="{{ $motionSettings['counters'] ? 'true' : 'false' }}" data-motion-hover="{{ $motionSettings['hover'] ? 'true' : 'false' }}">
        @foreach ($widgets as $widget)
            @if ($widget === 'users')
                <section data-dashboard-widget="users" data-size="{{ $widgetSizes['users'] ?? 'medium' }}" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Registered Accounts</div>
                    <div class="mt-1 text-2xl font-extrabold"><span data-count-to="{{ $metrics['users'] }}" data-live-metric="users">{{ $metrics['users'] }}</span></div>
                </section>
            @elseif ($widget === 'plugins')
                <section data-dashboard-widget="plugins" data-size="{{ $widgetSizes['plugins'] ?? 'medium' }}" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-sm font-bold text-slate-900">Plugin Overview</h2><span class="text-xs text-slate-500"><span data-live-metric="plugins_total">{{ $metrics['plugins_total'] }}</span> discovered</span></div>
                    <div class="mt-2 text-2xl font-extrabold text-emerald-700"><span data-count-to="{{ $metrics['plugins_enabled'] }}" data-live-metric="plugins_enabled">{{ $metrics['plugins_enabled'] }}</span> <span class="text-sm font-medium text-slate-500">active</span></div>
                </section>
            @elseif ($widget === 'backups')
                <section data-dashboard-widget="backups" data-size="{{ $widgetSizes['backups'] ?? 'medium' }}" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Encrypted Backups</div>
                    <div class="mt-1 text-2xl font-extrabold"><span data-count-to="{{ $metrics['backups'] }}" data-live-metric="backups">{{ $metrics['backups'] }}</span></div>
                </section>
            @elseif ($widget === 'system_info')
                <section data-dashboard-widget="system_info" data-size="{{ $widgetSizes['system_info'] ?? 'medium' }}" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-bold text-slate-900">System Information</h2>
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><span class="text-slate-500">Core version:</span> <strong>{{ $metrics['core_version'] }}</strong></div>
                        <div><span class="text-slate-500">PHP runtime:</span> <strong>{{ $metrics['php_version'] }}</strong></div>
                    </div>
                </section>
            @elseif ($widget === 'audit_trail')
                <section data-dashboard-widget="audit_trail" data-size="{{ $widgetSizes['audit_trail'] ?? 'medium' }}" class="rounded-2xl border border-slate-200 bg-white p-5">
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
                @if (!empty($widgetDefinitions[$widget]['view']))
                    <div data-dashboard-widget="{{ $widget }}" data-size="{{ $widgetSizes[$widget] ?? 'medium' }}">
                        @include($widgetDefinitions[$widget]['view'], ['metrics' => $metrics, 'actor' => auth()->user()])
                    </div>
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
    if (dashboard?.dataset.refreshUrl) {
        const refresh = async () => {
            try {
                const response = await fetch(dashboard.dataset.refreshUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok) return;
                const payload = await response.json();
                Object.entries(payload.metrics || {}).forEach(([metric, value]) => {
                    dashboard.querySelectorAll('[data-live-metric="' + metric + '"]').forEach(node => { node.textContent = Number(value).toLocaleString(); });
                });
                dashboard.querySelectorAll('[data-refresh-status]').forEach(node => node.textContent = 'Updated ' + new Date(payload.refreshed_at).toLocaleTimeString());
            } catch (_) { /* Preserve the last successful snapshot while offline. */ }
        };
        window.setInterval(refresh, 60000);
        dashboard.querySelectorAll('[data-dashboard-widget]').forEach(widget => {
            const widgetKey = widget.dataset.dashboardWidget;
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!widgetKey || !token || !dashboard.dataset.analyticsUrl) return;
            fetch(dashboard.dataset.analyticsUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ widget: widgetKey })
            }).catch(() => {});
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
    document.getElementById('dashboard-preset')?.addEventListener('change', event => {
        const preset = event.target.value;
        const rules = {
            overview: ['users', 'plugins', 'backups', 'audit_trail', 'system_info'],
            operations: ['audit_trail', 'system_info', 'backups'],
            teaching: ['teacher', 'student', 'course', 'class', 'assessment', 'learning', 'live'],
            finance: ['payment', 'finance', 'revenue', 'invoice', 'fee', 'transaction']
        };
        rows().forEach(row => {
            const key = row.querySelector('input[type=checkbox]').value.toLowerCase();
            const enabled = preset === 'role'
                ? row.dataset.defaultEnabled === 'true'
                : (rules[preset] || []).some(term => preset === 'overview' || preset === 'operations' ? term === key : key.includes(term));
            row.querySelector('input[type=checkbox]').checked = enabled;
        });
    });
    document.getElementById('dashboard-reset-order')?.addEventListener('click', () => {
        rows().forEach(row => {
            list.appendChild(row);
            row.querySelector('input[type=checkbox]').checked = row.dataset.defaultEnabled === 'true';
        });
    });
})();
</script>

<style>
/* Dashboard-only reference skin; preserve global appearance preferences elsewhere. */
body:has(#dashboard-widgets) { background:#1b2533 !important; color:#e8eef7 !important; }
body:has(#dashboard-widgets) main { background:#1b2533 !important; }
body:has(#dashboard-widgets) main > header { background:#263244 !important; border-color:#354256 !important; }
body:has(#dashboard-widgets) main > header .text-slate-900 { color:#f8fafc !important; }
body:has(#dashboard-widgets) main > header .text-slate-500 { color:#aebbd0 !important; }
body:has(#dashboard-widgets) main > header a:not(.bg-blue-600) { background:#303d51 !important; border-color:#45536a !important; color:#e8eef7 !important; }
body:has(#dashboard-widgets) main > header span { background:#303d51 !important; color:#e8eef7 !important; }
#dashboard-widgets { align-items:stretch; gap:1rem; }
#dashboard-widgets [data-dashboard-widget] {
 min-width:0; color:#e8eef7 !important; background:#263244 !important;
 border:1px solid #3a485d !important; border-radius:12px !important;
 box-shadow:0 8px 22px rgba(5,12,24,.12); padding:1.15rem !important;
}
#dashboard-widgets [data-dashboard-widget] h1,
#dashboard-widgets [data-dashboard-widget] h2,
#dashboard-widgets [data-dashboard-widget] h3,
#dashboard-widgets [data-dashboard-widget] strong,
#dashboard-widgets [data-dashboard-widget] .text-slate-900 { color:#f8fafc !important; }
#dashboard-widgets [data-dashboard-widget] .text-slate-700,
#dashboard-widgets [data-dashboard-widget] .text-slate-600 { color:#d5deeb !important; }
#dashboard-widgets [data-dashboard-widget] .text-slate-500,
#dashboard-widgets [data-dashboard-widget] .text-slate-400 { color:#9eacc1 !important; }
#dashboard-widgets [data-dashboard-widget] .border-slate-200,
#dashboard-widgets [data-dashboard-widget] .border-slate-300,
#dashboard-widgets [data-dashboard-widget] table tbody tr { border-color:#3b4a60 !important; }
#dashboard-widgets [data-dashboard-widget] table thead { color:#aebbd0 !important; border-color:#3b4a60 !important; }
#dashboard-widgets [data-dashboard-widget] a.bg-blue-600,
#dashboard-widgets [data-dashboard-widget] button.bg-blue-600 { background:#20a89c !important; color:white !important; }
#dashboard-widgets [data-dashboard-widget] input,
#dashboard-widgets [data-dashboard-widget] select { background:#202c3c !important; border-color:#43536b !important; color:#e8eef7 !important; }
#dashboard-widgets [data-dashboard-widget][data-size=small] { min-height:8rem; }
#dashboard-widgets [data-dashboard-widget][data-size=medium] { min-height:10rem; }
#dashboard-widgets [data-dashboard-widget][data-size=large] { min-height:14rem; }
@media (min-width:768px) {
 #dashboard-widgets [data-dashboard-widget][data-size=small] { grid-column:span 1; }
 #dashboard-widgets [data-dashboard-widget][data-size=medium] { grid-column:span 2; }
 #dashboard-widgets [data-dashboard-widget][data-size=large] { grid-column:span 4; }
}
@media (max-width:767px) {
 #dashboard-widgets [data-dashboard-widget] { grid-column:1 / -1 !important; }
 body:has(#dashboard-widgets) main { padding:1rem !important; }
 body:has(#dashboard-widgets) main > header { margin:-1rem -1rem 1rem !important; padding:1rem !important; }
}
#dashboard-widgets [data-dashboard-widget] { transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease; }
#dashboard-widgets [data-dashboard-widget]:hover { transform:translateY(-2px); border-color:#52647e !important; box-shadow:0 14px 30px rgba(5,12,24,.22); }
@media (prefers-reduced-motion:reduce) {
 #dashboard-widgets [data-dashboard-widget] { transition:none !important; }
 #dashboard-widgets [data-dashboard-widget]:hover { transform:none; }
}

/* EDUPro-inspired admin dashboard skin. Kept local to this page so other portals and admin screens are unchanged. */
body:has(#dashboard-widgets) { background:#111c2e !important; color:#e8eef7 !important; }
body:has(#dashboard-widgets) main { background:#111c2e !important; }
body:has(#dashboard-widgets) #admin-sidebar {
    background:linear-gradient(180deg,#102344 0%,#0b1930 52%,#081426 100%) !important;
    border-color:rgba(148,163,184,.14) !important;
}
body:has(#dashboard-widgets) #admin-sidebar > div:first-child {
    background:linear-gradient(145deg,#17345b,#102443) !important;
    border-color:rgba(148,163,184,.16) !important;
}
body:has(#dashboard-widgets) #admin-sidebar nav a {
    margin-block:3px; border:1px solid transparent; border-radius:11px;
    color:#c5d2e4 !important; transition:background .18s ease,border-color .18s ease,transform .18s ease;
}
body:has(#dashboard-widgets) #admin-sidebar nav a:hover {
    background:rgba(96,165,250,.12) !important; border-color:rgba(96,165,250,.18) !important;
    color:#fff !important; transform:translateX(2px);
}
body:has(#dashboard-widgets) main > header {
    background:rgba(25,39,60,.94) !important; border-color:rgba(148,163,184,.16) !important;
    color:#f8fafc !important; backdrop-filter:blur(16px);
}
body:has(#dashboard-widgets) main > header .text-slate-900 { color:#f8fafc !important; }
body:has(#dashboard-widgets) main > header .text-slate-500,
body:has(#dashboard-widgets) main > header .text-slate-400 { color:#a9bad0 !important; }
body:has(#dashboard-widgets) main > header a:not(.bg-blue-600),
body:has(#dashboard-widgets) main > header button:not(.bg-blue-600) {
    background:#223550 !important; border-color:#3b5270 !important; color:#e8eef7 !important;
}
.aec-edupro-hero {
    position:relative; isolation:isolate; overflow:hidden; min-height:158px;
    display:flex; align-items:center; padding:1.6rem 1.8rem; border-radius:22px;
    color:#fff; background:linear-gradient(112deg,#12345b 0%,#155b78 52%,#176f78 100%);
    border:1px solid rgba(125,211,252,.24); box-shadow:0 18px 44px rgba(2,8,23,.2);
}
.aec-edupro-hero__content { position:relative; z-index:2; display:flex; width:100%; align-items:flex-end; justify-content:space-between; gap:1.5rem; }
.aec-edupro-hero__eyebrow { margin:0 0 .45rem; color:#a5f3fc; font-size:.65rem; font-weight:800; letter-spacing:.16em; }
.aec-edupro-hero h2 { margin:0; color:#fff; font-size:clamp(1.35rem,2.5vw,2rem); font-weight:850; letter-spacing:-.035em; }
.aec-edupro-hero h2 span { color:#fcd34d; font-size:.8em; }
.aec-edupro-hero__content p:not(.aec-edupro-hero__eyebrow) { margin:.45rem 0 0; max-width:42rem; color:#d7f4f7; font-size:.82rem; }
.aec-edupro-hero__date { display:flex; flex:none; align-items:center; gap:.5rem; padding:.65rem .85rem; border:1px solid rgba(255,255,255,.22); border-radius:12px; background:rgba(7,24,48,.24); color:#e0f2fe; font-size:.72rem; font-weight:700; backdrop-filter:blur(10px); }
.aec-edupro-hero__orb { position:absolute; z-index:1; border-radius:999px; pointer-events:none; filter:blur(1px); }
.aec-edupro-hero__orb--one { width:230px; height:230px; right:12%; top:-155px; background:rgba(103,232,249,.13); box-shadow:0 0 80px rgba(103,232,249,.14); }
.aec-edupro-hero__orb--two { width:150px; height:150px; right:2%; bottom:-105px; background:rgba(96,165,250,.2); }
#dashboard-widgets { align-items:stretch; gap:1rem; }
#dashboard-widgets [data-dashboard-widget] {
    position:relative; min-width:0; overflow:hidden; color:#e8eef7 !important;
    background:linear-gradient(145deg,#202f45 0%,#1a293e 100%) !important;
    border:1px solid rgba(148,163,184,.2) !important; border-radius:17px !important;
    box-shadow:0 12px 28px rgba(2,8,23,.16); padding:1.25rem !important;
}
#dashboard-widgets [data-dashboard-widget]::after {
    content:""; position:absolute; width:120px; height:120px; right:-55px; top:-65px;
    border-radius:50%; background:rgba(96,165,250,.09); pointer-events:none;
}
#dashboard-widgets [data-dashboard-widget="users"] {
    background:linear-gradient(135deg,#2366a5,#244b87) !important;
    border-color:rgba(125,211,252,.28) !important;
}
#dashboard-widgets [data-dashboard-widget="plugins"] {
    background:linear-gradient(135deg,#087f83,#126b75) !important;
    border-color:rgba(94,234,212,.25) !important;
}
#dashboard-widgets [data-dashboard-widget="backups"] {
    background:linear-gradient(135deg,#594a9b,#3c477f) !important;
    border-color:rgba(196,181,253,.25) !important;
}
#dashboard-widgets [data-dashboard-widget="users"]::after { background:rgba(191,219,254,.14); }
#dashboard-widgets [data-dashboard-widget="plugins"]::after { background:rgba(153,246,228,.14); }
#dashboard-widgets [data-dashboard-widget="backups"]::after { background:rgba(221,214,254,.15); }
#dashboard-widgets [data-dashboard-widget="users"] .text-slate-500,
#dashboard-widgets [data-dashboard-widget="plugins"] .text-slate-500,
#dashboard-widgets [data-dashboard-widget="backups"] .text-slate-500 { color:rgba(255,255,255,.78) !important; }
#dashboard-widgets [data-dashboard-widget="users"] .text-2xl,
#dashboard-widgets [data-dashboard-widget="plugins"] .text-2xl,
#dashboard-widgets [data-dashboard-widget="backups"] .text-2xl { color:#fff !important; font-size:2rem; letter-spacing:-.04em; }
#dashboard-widgets [data-dashboard-widget] h1,
#dashboard-widgets [data-dashboard-widget] h2,
#dashboard-widgets [data-dashboard-widget] h3,
#dashboard-widgets [data-dashboard-widget] strong,
#dashboard-widgets [data-dashboard-widget] .text-slate-900 { color:#f8fafc !important; }
#dashboard-widgets [data-dashboard-widget] .text-slate-700,
#dashboard-widgets [data-dashboard-widget] .text-slate-600 { color:#d5deeb !important; }
#dashboard-widgets [data-dashboard-widget] .text-slate-500,
#dashboard-widgets [data-dashboard-widget] .text-slate-400 { color:#a9bad0 !important; }
#dashboard-widgets [data-dashboard-widget] .border-slate-200,
#dashboard-widgets [data-dashboard-widget] .border-slate-300,
#dashboard-widgets [data-dashboard-widget] table tbody tr { border-color:rgba(148,163,184,.2) !important; }
#dashboard-widgets [data-dashboard-widget] table thead { color:#b8c8dc !important; border-color:rgba(148,163,184,.2) !important; }
#dashboard-widgets [data-dashboard-widget] table { color:#dce6f2; }
#dashboard-widgets [data-dashboard-widget] a.bg-blue-600,
#dashboard-widgets [data-dashboard-widget] button.bg-blue-600 { background:#20a89c !important; color:white !important; }
#dashboard-widgets [data-dashboard-widget] input,
#dashboard-widgets [data-dashboard-widget] select { background:#17263b !important; border-color:#43536b !important; color:#e8eef7 !important; }
#dashboard-widgets [data-dashboard-widget][data-size=small] { min-height:8rem; }
#dashboard-widgets [data-dashboard-widget][data-size=medium] { min-height:10rem; }
#dashboard-widgets [data-dashboard-widget][data-size=large] { min-height:14rem; }
@media (min-width:768px) {
    #dashboard-widgets [data-dashboard-widget][data-size=small] { grid-column:span 1; }
    #dashboard-widgets [data-dashboard-widget][data-size=medium] { grid-column:span 2; }
    #dashboard-widgets [data-dashboard-widget][data-size=large] { grid-column:span 4; }
}
@media (max-width:767px) {
    .aec-edupro-hero { min-height:0; padding:1.25rem; }
    .aec-edupro-hero__content { align-items:flex-start; flex-direction:column; gap:1rem; }
    .aec-edupro-hero__date { align-self:flex-start; }
    #dashboard-widgets [data-dashboard-widget] { grid-column:1 / -1 !important; }
    body:has(#dashboard-widgets) main { padding:1rem !important; }
    body:has(#dashboard-widgets) main > header { margin:-1rem -1rem 1rem !important; padding:1rem !important; }
}
#dashboard-widgets [data-dashboard-widget] { transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease; }
#dashboard-widgets [data-dashboard-widget]:hover { transform:translateY(-2px); border-color:#607795 !important; box-shadow:0 16px 34px rgba(2,8,23,.25); }
@media (prefers-reduced-motion:reduce) {
    #dashboard-widgets [data-dashboard-widget] { transition:none !important; }
    #dashboard-widgets [data-dashboard-widget]:hover { transform:none; }
    body:has(#dashboard-widgets) #admin-sidebar nav a { transition:none !important; }
}

</style>

@endsection
