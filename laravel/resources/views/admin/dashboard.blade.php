@extends('layouts.admin')
@section('title', 'Dashboard — AEC LMS')
@section('content')
@php
    $widgetLabels = collect($widgetDefinitions)->mapWithKeys(
        fn (array $definition, string $key): array => [$key => $definition['label']]
    )->all();
@endphp
<div class="aec-edupro-dashboard space-y-6">
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

    <section class="aec-dashboard-welcome relative isolate overflow-hidden rounded-3xl px-6 py-7 text-white shadow-xl sm:px-8 sm:py-9" aria-labelledby="aec-dashboard-welcome-title">
        <div class="aec-dashboard-welcome__gradient" aria-hidden="true"></div>
        <div class="aec-dashboard-welcome__ribbon aec-dashboard-welcome__ribbon--one" aria-hidden="true"></div>
        <div class="aec-dashboard-welcome__ribbon aec-dashboard-welcome__ribbon--two" aria-hidden="true"></div>
        <div class="aec-dashboard-welcome__glow aec-dashboard-welcome__glow--one" aria-hidden="true"></div>
        <div class="aec-dashboard-welcome__glow aec-dashboard-welcome__glow--two" aria-hidden="true"></div>
        <div class="relative z-10 grid items-center gap-7 lg:grid-cols-[1.2fr_.8fr]">
            <div class="max-w-2xl">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[10px] font-extrabold uppercase tracking-[.2em] text-cyan-100">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-300" aria-hidden="true"></span>
                    {{ config('app.name', 'Alom’s English Center') }} · LMS Workspace
                </span>
                <h2 id="aec-dashboard-welcome-title" class="mt-4 text-3xl font-black tracking-tight text-white sm:text-4xl">Welcome Back, {{ auth()->user()?->name ?? 'Admin' }}! <span aria-hidden="true">👋</span></h2>
                <p class="mt-2 text-base font-semibold text-blue-100 sm:text-lg">Your institution. Your learners. One connected workspace.</p>
                <p class="mt-1 text-sm italic text-cyan-100">Dream Big… <span class="font-extrabold text-amber-300">Learn Better.</span></p>
                <a href="#aec-dashboard-overview" class="mt-5 inline-flex items-center gap-3 rounded-full bg-amber-400 px-5 py-3 text-sm font-extrabold text-blue-950 shadow-lg shadow-blue-950/25 transition hover:-translate-y-0.5 hover:bg-yellow-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    View Dashboard <span aria-hidden="true">→</span>
                </a>
            </div>
            <div class="aec-dashboard-welcome__mission relative hidden min-h-36 items-center justify-center rounded-2xl border border-white/15 bg-slate-950/15 p-6 text-center backdrop-blur-sm sm:flex">
                <div>
                    <div class="text-4xl font-black leading-none text-amber-300" aria-hidden="true">“</div>
                    <p class="font-serif text-3xl font-bold italic leading-tight text-white">Your Success<br><span class="text-amber-300">Our Mission</span></p>
                    <div class="mx-auto mt-3 h-1 w-32 -rotate-6 rounded-full bg-amber-400"></div>
                </div>
            </div>
        </div>
        <div class="relative z-10 mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-white/15 pt-4 text-xs text-blue-100">
            <span>{{ now()->translatedFormat('l, d F Y') }}</span>
            <span class="rounded-full bg-emerald-400/15 px-3 py-1 font-bold text-emerald-200">● System workspace</span>
        </div>
    </section>

    <section id="aec-dashboard-overview" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Institution overview">
        @foreach ([
            ['key' => 'students', 'label' => 'Total Students', 'icon' => '♟', 'tone' => 'blue', 'caption' => 'Student records'],
            ['key' => 'teachers', 'label' => 'Total Teachers', 'icon' => '♙', 'tone' => 'green', 'caption' => 'Teacher records'],
            ['key' => 'courses', 'label' => 'Total Courses', 'icon' => '▤', 'tone' => 'violet', 'caption' => 'Course catalogue'],
            ['key' => 'batches', 'label' => 'Total Batches', 'icon' => '▱', 'tone' => 'orange', 'caption' => 'Learning groups'],
        ] as $stat)
            <article class="aec-dashboard-stat aec-dashboard-stat--{{ $stat['tone'] }} relative overflow-hidden rounded-2xl p-4 text-white shadow-lg">
                <div class="relative z-10 flex items-center gap-4">
                    <div class="aec-dashboard-stat__icon flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-3xl" aria-hidden="true">{{ $stat['icon'] }}</div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-white/85">{{ $stat['label'] }}</p>
                        <p class="mt-0.5 text-3xl font-black tracking-tight tabular-nums"><span data-live-metric="{{ $stat['key'] }}" data-count-to="{{ $metrics[$stat['key']] }}">{{ number_format($metrics[$stat['key']]) }}</span></p>
                        <p class="mt-0.5 text-[10px] font-medium text-white/75">{{ $stat['caption'] }}</p>
                    </div>
                </div>
                <span class="aec-dashboard-stat__wave" aria-hidden="true"></span>
            </article>
        @endforeach
    </section>

    <section class="grid grid-cols-1 gap-4 xl:grid-cols-12" aria-label="Dashboard analytics and activity">
        <article class="aec-dashboard-panel xl:col-span-7">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2"><span class="text-2xl" aria-hidden="true">📊</span><div><h2 class="text-base font-extrabold text-slate-900">Analytics Overview</h2><p class="text-xs text-slate-500">New records created over the last six months</p></div></div>
                <span class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">Last 6 Months</span>
            </div>
            @php
                $trendMax = max(1, max(array_merge($dashboardAnalytics['students'], $dashboardAnalytics['courses'], $dashboardAnalytics['batches'])));
            @endphp
            <div class="mt-5 flex flex-wrap items-center gap-4 text-[11px] font-semibold text-slate-600">
                <span class="inline-flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-blue-600"></i>Students</span>
                <span class="inline-flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-emerald-500"></i>Courses</span>
                <span class="inline-flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-violet-600"></i>Batches</span>
            </div>
            <div class="mt-4 grid h-44 grid-cols-6 items-end gap-3 border-b border-slate-200 px-1">
                @foreach ($dashboardAnalytics['labels'] as $index => $month)
                    <div class="flex h-full flex-col items-center justify-end gap-2">
                        <div class="flex h-full w-full items-end justify-center gap-1">
                            @foreach (['students' => 'bg-blue-600', 'courses' => 'bg-emerald-500', 'batches' => 'bg-violet-600'] as $series => $color)
                                @php $value = $dashboardAnalytics[$series][$index] ?? 0; @endphp
                                <div class="aec-dashboard-chart-bar {{ $color }} w-3 rounded-t-md sm:w-5" style="height:{{ max(4, (int) round(($value / $trendMax) * 100)) }}%" title="{{ ucfirst($series) }}: {{ number_format($value) }}" role="img" aria-label="{{ $month }} {{ $series }}: {{ number_format($value) }}"></div>
                            @endforeach
                        </div>
                        <span class="pb-2 text-[10px] font-semibold text-slate-500">{{ $month }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[10px] text-slate-400">Counts use available module records and their creation dates. Missing modules are shown as zero.</p>
        </article>

        <article class="aec-dashboard-panel xl:col-span-5">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2"><span class="text-2xl" aria-hidden="true">⚡</span><div><h2 class="text-base font-extrabold text-slate-900">Recent Activities</h2><p class="text-xs text-slate-500">Latest recorded platform events</p></div></div>
                @if (\Illuminate\Support\Facades\Route::has('admin.audit.index'))
                    <a href="{{ route('admin.audit.index') }}" class="text-xs font-bold text-blue-700 hover:text-blue-900">View all →</a>
                @endif
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($recentAudits as $log)
                    <div class="flex min-w-0 items-start gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm text-blue-700" aria-hidden="true">✓</span>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-xs font-bold text-slate-800">{{ \Illuminate\Support\Str::headline($log->action) }}</p>
                            <p class="mt-0.5 truncate text-[10px] text-slate-500">{{ $log->user?->name ?? 'System' }} · {{ $log->entity_type }}</p>
                        </div>
                        <time class="shrink-0 text-[10px] text-slate-400" datetime="{{ optional($log->created_at)->toIso8601String() }}">{{ optional($log->created_at)->diffForHumans() }}</time>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-xs text-slate-500">Activity will appear here as authorized platform actions are recorded.</div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="aec-dashboard-panel" aria-label="Quick actions">
        <div class="mb-4 flex items-center gap-2"><span class="text-2xl" aria-hidden="true">🚀</span><div><h2 class="text-base font-extrabold text-slate-900">Quick Actions</h2><p class="text-xs text-slate-500">Shortcuts to the management areas available to your account</p></div></div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
            @if (auth()->user()?->hasPermission('core.users.manage') && \Illuminate\Support\Facades\Route::has('admin.users.index'))
                <a href="{{ route('admin.users.index') }}" class="aec-dashboard-action"><span>👥</span><strong>Manage Users</strong></a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('admin.plugins.index'))
                <a href="{{ route('admin.plugins.index') }}" class="aec-dashboard-action"><span>🧩</span><strong>Plugin Center</strong></a>
            @endif
            @if (auth()->user()?->hasPermission('core.settings.manage') && \Illuminate\Support\Facades\Route::has('admin.setup-wizard.index'))
                <a href="{{ route('admin.setup-wizard.index') }}" class="aec-dashboard-action"><span>⚙️</span><strong>Setup Wizard</strong></a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('admin.backups.index'))
                <a href="{{ route('admin.backups.index') }}" class="aec-dashboard-action"><span>🗄️</span><strong>Backups</strong></a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('admin.audit.index'))
                <a href="{{ route('admin.audit.index') }}" class="aec-dashboard-action"><span>📋</span><strong>Audit Logs</strong></a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('admin.appearance.index'))
                <a href="{{ route('admin.appearance.index') }}" class="aec-dashboard-action"><span>🎨</span><strong>Appearance</strong></a>
            @endif
        </div>
    </section>

    <style>
    body.aec-admin-dashboard-page { background:linear-gradient(180deg,#f8fbff 0%,#f1f5fb 100%) !important; }
    body.aec-admin-dashboard-page main { max-width:none; }
    body.aec-admin-dashboard-page #admin-sidebar {
        background:linear-gradient(180deg,#0b1d42 0%,#102b59 58%,#0b1b3b 100%) !important;
        border-right-color:rgba(148,163,184,.16) !important;
        box-shadow:12px 0 36px rgba(15,23,42,.08);
    }
    body.aec-admin-dashboard-page #admin-sidebar > div:first-child {
        background:linear-gradient(145deg,rgba(24,57,105,.82),rgba(12,32,68,.4)) !important;
        border-bottom-color:rgba(148,163,184,.16) !important;
    }
    body.aec-admin-dashboard-page #admin-sidebar nav a {
        display:flex; align-items:center; min-height:40px; margin-block:3px;
        border:1px solid transparent; border-radius:11px; color:#c6d4e9 !important;
        transition:background-color 180ms ease,border-color 180ms ease,color 180ms ease,transform 180ms ease;
    }
    body.aec-admin-dashboard-page #admin-sidebar nav a:hover {
        background:rgba(96,165,250,.12) !important; border-color:rgba(96,165,250,.18) !important;
        color:#fff !important; transform:translateX(2px);
    }
    body.aec-admin-dashboard-page #admin-sidebar nav a[aria-current="page"] {
        background:linear-gradient(105deg,#2563eb,#4f6fe8) !important;
        border-color:rgba(191,219,254,.24) !important; color:#fff !important;
        box-shadow:0 8px 20px rgba(30,64,175,.28);
    }
    body.aec-admin-dashboard-page #admin-sidebar nav > div { color:#8fa6c7 !important; letter-spacing:.15em; }
    body.aec-admin-dashboard-page main > header {
        background:rgba(255,255,255,.94) !important; border-bottom-color:#e4ebf5 !important;
        box-shadow:0 8px 24px rgba(15,23,42,.045) !important; color:#0f172a !important;
    }
    body.aec-admin-dashboard-page main > header .text-slate-900 { color:#0f172a !important; }
    body.aec-admin-dashboard-page main > header .text-slate-500,
    body.aec-admin-dashboard-page main > header .text-slate-400 { color:#64748b !important; }
    body.aec-admin-dashboard-page main > header a:not(.bg-blue-600) {
        background:#fff !important; border-color:#e2e8f0 !important; color:#334155 !important;
    }
    .aec-dashboard-welcome { isolation:isolate; min-height:270px; background:#071d63; }
    .aec-dashboard-welcome__gradient { position:absolute; inset:0; z-index:-3; background:radial-gradient(ellipse at 78% 35%,rgba(0,196,255,.55),transparent 32%),radial-gradient(ellipse at 15% 120%,rgba(127,56,255,.55),transparent 38%),linear-gradient(112deg,#071846 0%,#123a9a 48%,#075d9a 100%); }
    .aec-dashboard-welcome__ribbon { position:absolute; z-index:-2; pointer-events:none; width:75%; height:130%; top:-15%; border:1px solid rgba(100,220,255,.22); border-radius:50%; transform:rotate(-18deg); }
    .aec-dashboard-welcome__ribbon--one { right:-18%; box-shadow:0 0 0 18px rgba(80,170,255,.04),0 0 0 38px rgba(80,170,255,.035); }
    .aec-dashboard-welcome__ribbon--two { right:-30%; top:-45%; border-color:rgba(255,255,255,.16); transform:rotate(-32deg); }
    .aec-dashboard-welcome__mission { background:linear-gradient(145deg,rgba(8,25,73,.55),rgba(13,77,150,.25)); }
    .aec-dashboard-stat { min-height:112px; isolation:isolate; }
    .aec-dashboard-stat--blue { background:linear-gradient(120deg,#0878ef,#0750cb 70%,#0284c7); }
    .aec-dashboard-stat--green { background:linear-gradient(120deg,#0bb77b,#07865f 70%,#047857); }
    .aec-dashboard-stat--violet { background:linear-gradient(120deg,#8b32f2,#6d20c8 70%,#581c87); }
    .aec-dashboard-stat--orange { background:linear-gradient(120deg,#ff9b13,#ed6b00 70%,#c2410c); }
    .aec-dashboard-stat__icon { background:rgba(255,255,255,.17); box-shadow:inset 0 0 0 1px rgba(255,255,255,.18),0 8px 24px rgba(0,0,0,.12); }
    .aec-dashboard-stat__wave { position:absolute; z-index:-1; width:180px; height:90px; right:-35px; bottom:-35px; border:1px solid rgba(255,255,255,.24); border-radius:50%; transform:rotate(-22deg); box-shadow:0 0 0 12px rgba(255,255,255,.05),0 0 0 26px rgba(255,255,255,.035); }
    .aec-dashboard-panel { min-width:0; border:1px solid #e4eaf5; border-radius:1.25rem; background:rgba(255,255,255,.96); padding:1.15rem; box-shadow:0 10px 28px rgba(23,37,84,.07); }
    .aec-dashboard-chart-bar { min-height:3px; box-shadow:0 2px 8px rgba(37,99,235,.14); }
    .aec-dashboard-action { display:flex; min-height:82px; flex-direction:column; align-items:center; justify-content:center; gap:.4rem; border:1px solid #e4eaf5; border-radius:1rem; background:linear-gradient(145deg,#f8fbff,#eef4ff); padding:.7rem; text-align:center; text-decoration:none; transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease; }
    .aec-dashboard-action:hover { transform:translateY(-2px); border-color:#93c5fd; box-shadow:0 8px 20px rgba(37,99,235,.1); }
    .aec-dashboard-action span { font-size:1.45rem; }
    .aec-dashboard-action strong { color:#17336f; font-size:.7rem; }

    .aec-dashboard-welcome__glow { position:absolute; z-index:-1; pointer-events:none; border-radius:999px; filter:blur(2px); }
    .aec-dashboard-welcome__glow--one {
        width:270px; height:270px; right:7%; top:-190px;
        background:rgba(103,232,249,.18); box-shadow:0 0 90px rgba(103,232,249,.18);
    }
    .aec-dashboard-welcome__glow--two { width:190px; height:190px; right:28%; bottom:-150px; background:rgba(96,165,250,.18); }
    .aec-dashboard-welcome__date { min-width:220px; text-align:right; }
    .aec-dashboard-welcome__action { text-decoration:none; }
    #dashboard-widgets { align-items:stretch; gap:1rem; }
    #dashboard-widgets [data-dashboard-widget] {
        position:relative; min-width:0; overflow:hidden;
        border:1px solid #e3eaf4 !important; border-radius:1.15rem !important;
        background:#fff !important; color:#17243a !important;
        box-shadow:0 10px 28px rgba(15,23,42,.055);
        transition:transform 200ms ease,box-shadow 200ms ease,border-color 200ms ease;
    }
    #dashboard-widgets [data-dashboard-widget]::before {
        content:""; position:absolute; inset:0 0 auto; height:3px;
        background:linear-gradient(90deg,#2563eb,#14b8a6,#8b5cf6); opacity:.8; pointer-events:none;
    }
    #dashboard-widgets [data-dashboard-widget] h1,
    #dashboard-widgets [data-dashboard-widget] h2,
    #dashboard-widgets [data-dashboard-widget] h3,
    #dashboard-widgets [data-dashboard-widget] strong,
    #dashboard-widgets [data-dashboard-widget] .text-slate-900 { color:#17243a !important; }
    #dashboard-widgets [data-dashboard-widget] .text-slate-700,
    #dashboard-widgets [data-dashboard-widget] .text-slate-600 { color:#475569 !important; }
    #dashboard-widgets [data-dashboard-widget] .text-slate-500,
    #dashboard-widgets [data-dashboard-widget] .text-slate-400 { color:#64748b !important; }
    #dashboard-widgets [data-dashboard-widget] .border-slate-200,
    #dashboard-widgets [data-dashboard-widget] .border-slate-300 { border-color:#e2e8f0 !important; }
    #dashboard-widgets [data-dashboard-widget="users"] {
        border-color:rgba(37,99,235,.22) !important;
        background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 58%,#1e40af 100%) !important; color:#fff !important;
    }
    #dashboard-widgets [data-dashboard-widget="plugins"] {
        border-color:rgba(13,148,136,.22) !important;
        background:linear-gradient(135deg,#0f9b8e 0%,#0f766e 100%) !important; color:#fff !important;
    }
    #dashboard-widgets [data-dashboard-widget="backups"] {
        border-color:rgba(124,58,237,.22) !important;
        background:linear-gradient(135deg,#7655c9 0%,#5540a3 100%) !important; color:#fff !important;
    }
    #dashboard-widgets [data-dashboard-widget="users"]::after,
    #dashboard-widgets [data-dashboard-widget="plugins"]::after,
    #dashboard-widgets [data-dashboard-widget="backups"]::after {
        content:""; position:absolute; width:145px; height:145px; right:-70px; top:-82px;
        border:1px solid rgba(255,255,255,.16); border-radius:50%;
        box-shadow:0 0 0 18px rgba(255,255,255,.035),0 0 0 38px rgba(255,255,255,.025);
        pointer-events:none;
    }
    #dashboard-widgets [data-dashboard-widget="users"] .text-xs,
    #dashboard-widgets [data-dashboard-widget="plugins"] .text-xs,
    #dashboard-widgets [data-dashboard-widget="backups"] .text-xs,
    #dashboard-widgets [data-dashboard-widget="plugins"] .text-slate-500,
    #dashboard-widgets [data-dashboard-widget="users"] .text-slate-500,
    #dashboard-widgets [data-dashboard-widget="backups"] .text-slate-500 { color:rgba(255,255,255,.82) !important; }
    #dashboard-widgets [data-dashboard-widget="users"] .text-2xl,
    #dashboard-widgets [data-dashboard-widget="plugins"] .text-2xl,
    #dashboard-widgets [data-dashboard-widget="backups"] .text-2xl { color:#fff !important; font-size:2rem; letter-spacing:-.04em; }
    #dashboard-widgets [data-dashboard-widget="plugins"] h2 { color:#fff !important; }
    #dashboard-widgets [data-dashboard-widget][data-size="small"] { min-height:7.5rem; }
    #dashboard-widgets [data-dashboard-widget][data-size="medium"] { min-height:10rem; }
    #dashboard-widgets [data-dashboard-widget][data-size="large"] { min-height:14rem; }
    #dashboard-widgets[data-motion-enabled="true"][data-motion-hover="true"] [data-dashboard-widget]:hover {
        transform:translateY(-3px); border-color:rgba(37,99,235,.34) !important;
        box-shadow:0 16px 36px rgba(15,23,42,.11);
    }
    @media (min-width:768px) {
        #dashboard-widgets [data-dashboard-widget][data-size="small"] { grid-column:span 1; }
        #dashboard-widgets [data-dashboard-widget][data-size="medium"] { grid-column:span 2; }
        #dashboard-widgets [data-dashboard-widget][data-size="large"] { grid-column:span 4; }
    }
    @media (max-width:767px) {
        .aec-dashboard-welcome { min-height:0; padding:1.25rem; }
        .aec-dashboard-stat { min-height:100px; }
        .aec-dashboard-welcome__date { min-width:0; width:100%; text-align:left; }
        .aec-dashboard-panel { padding:.9rem; }
        #dashboard-widgets [data-dashboard-widget] { grid-column:1 / -1 !important; }
        body.aec-admin-dashboard-page main { padding:1rem !important; }
        body.aec-admin-dashboard-page main > header { margin:-1rem -1rem 1rem !important; padding:1rem !important; }
    }
    @media (prefers-reduced-motion:reduce) {
        #dashboard-widgets [data-dashboard-widget],
        #dashboard-widgets [data-dashboard-widget]:hover,
        body.aec-admin-dashboard-page #admin-sidebar nav a { transition:none !important; transform:none !important; }
    }
</style>
<div id="dashboard-widgets" class="grid grid-cols-1 gap-4 md:grid-cols-4" data-refresh-url="{{ route('admin.dashboard.refresh') }}" data-analytics-url="{{ route('admin.dashboard.widget-analytics') }}" data-motion-enabled="{{ $motionSettings['enabled'] ? 'true' : 'false' }}" data-motion-preset="{{ $motionSettings['preset'] }}" data-motion-speed="{{ $motionSettings['speed'] }}" data-motion-intensity="{{ $motionSettings['intensity'] }}" data-motion-loading="{{ $motionSettings['loading'] }}" data-motion-counters="{{ $motionSettings['counters'] ? 'true' : 'false' }}" data-motion-hover="{{ $motionSettings['hover'] ? 'true' : 'false' }}">
        @foreach ($widgets as $widget)
            @if ($widget === 'users')
                <section data-dashboard-widget="users" data-size="{{ $widgetSizes['users'] ?? 'medium' }}" class="aec-dashboard-kpi aec-dashboard-kpi--users rounded-2xl border p-5">
                    <div class="text-xs font-bold uppercase tracking-[.12em] text-slate-500">Registered Accounts</div>
                    <div class="mt-2 text-2xl font-extrabold tabular-nums"><span data-count-to="{{ $metrics['users'] }}" data-live-metric="users">{{ number_format($metrics['users']) }}</span></div>
                    <p class="mt-1 text-[11px] font-medium text-slate-500">All platform user accounts</p>
                </section>
            @elseif ($widget === 'plugins')
                <section data-dashboard-widget="plugins" data-size="{{ $widgetSizes['plugins'] ?? 'medium' }}" class="aec-dashboard-kpi aec-dashboard-kpi--plugins rounded-2xl border p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-sm font-bold text-slate-900">Plugin Overview</h2><span class="text-xs text-slate-500"><span data-live-metric="plugins_total">{{ $metrics['plugins_total'] }}</span> discovered</span></div>
                    <div class="mt-2 text-2xl font-extrabold text-emerald-700"><span data-count-to="{{ $metrics['plugins_enabled'] }}" data-live-metric="plugins_enabled">{{ $metrics['plugins_enabled'] }}</span> <span class="text-sm font-medium text-slate-500">active</span></div>
                </section>
            @elseif ($widget === 'backups')
                <section data-dashboard-widget="backups" data-size="{{ $widgetSizes['backups'] ?? 'medium' }}" class="aec-dashboard-kpi aec-dashboard-kpi--backups rounded-2xl border p-5">
                    <div class="text-xs font-bold uppercase tracking-[.12em] text-slate-500">Encrypted Backups</div>
                    <div class="mt-2 text-2xl font-extrabold tabular-nums"><span data-count-to="{{ $metrics['backups'] }}" data-live-metric="backups">{{ number_format($metrics['backups']) }}</span></div>
                    <p class="mt-1 text-[11px] font-medium text-slate-500">Stored backup archives</p>
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

@endsection
