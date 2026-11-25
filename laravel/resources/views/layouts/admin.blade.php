<!doctype html>
@php
    $rtl = in_array(substr(strtolower(str_replace('_', '-', app()->getLocale())), 0, 2), ['ar', 'fa', 'he', 'ur', 'ps', 'dv'], true);
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel — ' . config('app.name'))</title>
    @php
        $activePluginFavicon = null;
        $activeRouteName = request()->route()?->getName();
        foreach (($pluginAdminMenu ?? []) as $pluginMenuGroup) {
            foreach ($pluginMenuGroup as $pluginMenuItem) {
                if (($pluginMenuItem['route'] ?? null) === $activeRouteName && !empty($pluginMenuItem['plugin'])) {
                    $activePluginFavicon = route('admin.plugins.favicon', $pluginMenuItem['plugin']);
                    break 2;
                }
            }
        }
    @endphp
    @if (!empty($activePluginFavicon))
        <link rel="icon" type="image/svg+xml" href="{{ $activePluginFavicon }}">
    @elseif (!empty($siteIdentity['favicon_url']))
        <link rel="icon" href="{{ $siteIdentity['favicon_url'] }}">
    @endif
    @inject('themeManager', 'App\\Core\\Appearance\\ThemeManager')
    <style>
        :root { {!! $themeManager->cssVariables() !!}; {!! $themeManager->cssDesignVariables() !!} }
        body { background-color:var(--aec-page-bg)!important;color:var(--aec-text)!important; }
        .bg-white { background-color:var(--aec-surface)!important; }
        .bg-slate-50 { background-color:var(--aec-surface-alt)!important; }
        .bg-slate-100 { background-color:var(--aec-page-bg)!important; }
        .text-slate-900 { color:var(--aec-text)!important; }
        .text-slate-500,.text-slate-400 { color:var(--aec-muted)!important; }
        .border-slate-200,.border-slate-300 { border-color:var(--aec-border)!important; }
        .text-blue-600,.text-blue-700 { color:var(--aec-link)!important; }
        .bg-blue-600 { background-color:var(--aec-primary)!important;color:var(--aec-button-text)!important; }
        .bg-sky-500,.bg-sky-600 { background-color:var(--aec-accent)!important; }
        .text-sky-500,.text-sky-600 { color:var(--aec-info)!important; }
        .bg-emerald-600,.bg-green-600 { background-color:var(--aec-success)!important; }
        .text-emerald-700,.text-green-700 { color:var(--aec-success)!important; }
        .bg-amber-500,.bg-amber-600 { background-color:var(--aec-warning)!important; }
        .text-amber-700,.text-yellow-700 { color:var(--aec-warning)!important; }
        .bg-rose-600,.bg-red-600 { background-color:var(--aec-danger)!important; }
        .text-rose-700,.text-red-700 { color:var(--aec-danger)!important; }
        header { background-color:var(--aec-header-bg); }
        .hover\:bg-blue-700:hover { background-color:var(--aec-primary-hover)!important; }
        input:not([type=color]),select,textarea { background-color:var(--aec-input-bg);color:var(--aec-input-text);border-color:var(--aec-border); }
        aside { background-color:var(--aec-sidebar-bg)!important;color:var(--aec-sidebar-text)!important; }
        aside > div:first-child { background-color:var(--aec-secondary)!important; }
        aside a:hover { background-color:color-mix(in srgb,var(--aec-sidebar-text) 12%,transparent)!important; }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family:var(--aec-font-family);font-size:var(--aec-font-size); }
        main { max-width:min(100%,var(--aec-content-width));margin-inline:auto; }
        h1,h2,h3,h4,h5,h6 { font-weight:var(--aec-heading-weight); }
        .rounded-xl { border-radius:var(--aec-radius); }
        .space-y-6 > :not([hidden]) ~ :not([hidden]) { margin-top:calc(1.5rem * var(--aec-density)); }
    </style>
    <style>
        @keyframes aec-progress-fill-enter {
            from { transform: scaleX(0); }
            to { transform: scaleX(1); }
        }
        @keyframes aec-progress-shimmer {
            0% { transform: translateX(-150%); opacity: 0; }
            18% { opacity: .38; }
            100% { transform: translateX(260%); opacity: 0; }
        }
        [role="progressbar"] > [data-progress-fill] {
            position: relative;
            overflow: hidden;
            transform-origin: left center;
            animation: aec-progress-fill-enter 850ms cubic-bezier(.22, 1, .36, 1) both;
            transition: width 700ms cubic-bezier(.22, 1, .36, 1);
        }
        [role="progressbar"]:not([aria-valuenow="100"]) > [data-progress-fill]::after {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 42%;
            pointer-events: none;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.48), transparent);
            animation: aec-progress-shimmer 2.4s ease-in-out 350ms infinite;
        }
        @media (prefers-reduced-motion: reduce) {
            [role="progressbar"] > [data-progress-fill] {
                animation: none !important;
                transition: none !important;
            }
            [role="progressbar"] > [data-progress-fill]::after {
                animation: none !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
<div class="min-h-screen flex">
    <aside id="admin-sidebar" class="fixed inset-y-0 left-0 z-40 w-64 text-slate-200 flex flex-col shrink-0 border-r border-white/10 -translate-x-full transition-transform duration-200 shadow-2xl lg:static lg:translate-x-0 lg:shadow-none">
        <div class="px-5 py-4 border-b border-white/10">
            <div class="flex items-center gap-2">
                @if (!empty($siteIdentity['logo_url']))
                    <img src="{{ $siteIdentity['logo_url'] }}" alt="{{ config('app.name') }} logo" class="h-9 max-w-28 object-contain">
                @endif
                <div>
                    <div class="text-sm font-extrabold tracking-tight text-white">{{ config('app.name') }}</div>
                    <div class="text-[11px] text-[#38BDF8] font-medium">{{ $siteIdentity['tagline'] ?? 'Core + Independent Plugins' }}</div>
                </div>
            </div>
            <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer"
               class="mt-3 flex items-center justify-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15 transition-colors"
               aria-label="Visit public website (opens in a new tab)">
                <span aria-hidden="true">↗</span>
                <span>{{ __('Visit Public Site') }}</span>
            </a>
        </div>
        <nav class="flex-1 p-3 space-y-1 text-sm overflow-y-auto">
            <div class="px-3 pt-1 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ __('Protected Core') }}</div>
            <a href="{{ route('admin.dashboard') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Dashboard') }}</a>
            @if (auth()->user()?->hasRole('admin') && auth()->user()?->hasPermission('core.settings.manage'))
                <a href="{{ route('admin.automation-saas.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">AI Automation SaaS</a>
            @endif
            <a href="{{ route('admin.plugins.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Plugin Center') }}</a>
            @if (auth()->user()?->hasPermission('core.users.manage'))
                <a href="{{ route('admin.users.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Users & Roles') }}</a>
                <a href="{{ route('admin.roles.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Roles & Permissions') }}</a>
            @endif
            <a href="{{ route('admin.updates.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Update Center') }}</a>
            <a href="{{ route('admin.backups.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Backup & Restore</a>
            <a href="{{ route('admin.audit.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Audit Logs') }}</a>
            @if (auth()->user()?->hasRole('admin') && auth()->user()?->hasPermission('core.audit.view'))
                <a href="{{ route('admin.security.sessions.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Device Sessions') }}</a>
            @endif
            @can('core.settings.manage')
                <a href="{{ route('admin.setup-wizard.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Setup Wizard') }}</a>
            @endcan
            <a href="{{ route('admin.institution-settings.edit') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Institution & Region') }}</a>
            @can('core.settings.manage')
                <a href="{{ route('admin.site-identity.edit') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Site Identity & Branding') }}</a>
            @endcan
            <a href="{{ route('admin.appearance.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Design & Appearance') }}</a>
            @can('core.settings.manage')
                <a href="{{ route('admin.menu-editor.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Admin Menu Editor') }}</a>
                <a href="{{ route('admin.site-navigation.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Website Navigation Builder') }}</a>
                <a href="{{ route('admin.course-social-proof.edit') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">{{ __('Course Views & Purchases') }}</a>
            @endcan

            @if (!empty($pluginAdminMenu))
                @foreach ($pluginAdminMenu as $groupName => $menuItems)
                    <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ __($groupName) }}</div>
                    @foreach ($menuItems as $menuItem)
                        <a href="{{ route($menuItem['route']) }}" class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs"><x-plugin-icon :name="$menuItem['icon'] ?? 'puzzle'" class="h-4 w-4 shrink-0 opacity-80" /><span>{{ __($menuItem['label']) }}</span></a>
                    @endforeach
                @endforeach
            @endif
        </nav>
        <div class="p-3 border-t border-slate-800 space-y-3">
            @include('public.partials.language-switcher')
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full text-left px-3 py-2 rounded-lg text-rose-300 hover:bg-slate-800 text-sm">{{ __('Sign Out') }}</button>
            </form>
        </div>
    </aside>
    <button type="button" id="admin-sidebar-backdrop" class="hidden fixed inset-0 z-30 bg-slate-950/50 lg:hidden" aria-label="Close navigation menu"></button>
    <main class="flex-1 min-w-0 p-4 sm:p-6 overflow-y-auto">
        <header class="sticky top-0 z-30 -mx-4 sm:-mx-6 -mt-4 sm:-mt-6 mb-6 border-b border-slate-200 px-4 sm:px-6 py-3 shadow-sm backdrop-blur">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">ALOMS LMS Core</div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="admin-sidebar-toggle" aria-controls="admin-sidebar" aria-expanded="false" class="inline-flex lg:hidden h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700" aria-label="Open navigation menu">☰</button>
                        <div class="text-sm font-extrabold text-slate-900">Admin Console <span class="font-medium text-slate-500">/ Core + Independent Plugins</span></div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.plugins.index') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-blue-300 hover:text-blue-700 transition-colors">＋ Plugin Center</a>
                    <a href="{{ route('admin.audit.index') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-blue-300 hover:text-blue-700 transition-colors">Audit Logs</a>
                    <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700 transition-colors">↗ Visit Website</a>
                    <span class="hidden md:inline-flex rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700">{{ auth()->user()?->name ?? 'Administrator' }}</span>
                </div>
            </div>
        </header>
        @if (session('status'))
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <ul class="list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @yield('content')
    </main>
</div>

@if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('role-ai-assistant') && Route::has('plugins.ai.ask'))
    @includeIf('role-ai-assistant::partials.widget')
@endif
<script>
(function () {
    const sidebar = document.getElementById('admin-sidebar');
    const toggle = document.getElementById('admin-sidebar-toggle');
    const backdrop = document.getElementById('admin-sidebar-backdrop');
    if (!sidebar || !toggle || !backdrop) return;

    const closeMenu = () => {
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    };
    const openMenu = () => {
        sidebar.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
    };

    toggle.addEventListener('click', () => {
        if (sidebar.classList.contains('-translate-x-full')) openMenu();
        else closeMenu();
    });
    backdrop.addEventListener('click', closeMenu);
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024) closeMenu();
    });
})();
</script>
</body>
</html>
