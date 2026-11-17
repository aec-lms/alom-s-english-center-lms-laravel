<!doctype html>
@php($rtl = in_array(substr(strtolower(str_replace('_', '-', app()->getLocale())), 0, 2), ['ar', 'fa', 'he', 'ur', 'ps', 'dv'], true))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel — ' . config('app.name'))</title>
    @inject('themeManager', 'App\\Core\\Appearance\\ThemeManager')
    <style>
        :root { {!! $themeManager->cssVariables() !!} }
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
<div class="min-h-screen flex">
    <aside id="admin-sidebar" class="fixed inset-y-0 left-0 z-40 w-64 text-slate-200 flex flex-col shrink-0 border-r border-white/10 -translate-x-full transition-transform duration-200 shadow-2xl lg:static lg:translate-x-0 lg:shadow-none">
        <div class="px-5 py-4 border-b border-white/10">
            <div class="text-sm font-extrabold tracking-tight text-white">{{ config('app.name') }} Core</div>
            <div class="text-[11px] text-[#38BDF8] font-medium">Core + Independent Plugins</div>
            <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer"
               class="mt-3 flex items-center justify-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15 transition-colors"
               aria-label="Visit public website (opens in a new tab)">
                <span aria-hidden="true">↗</span>
                <span>Visit Public Site</span>
            </a>
        </div>
        <nav class="flex-1 p-3 space-y-1 text-sm overflow-y-auto">
            <div class="px-3 pt-1 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Protected Core</div>
            <a href="{{ route('admin.dashboard') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Dashboard</a>
            <a href="{{ route('admin.plugins.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Plugin Center</a>
            <a href="{{ route('admin.users.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Users & Roles</a>
            <a href="{{ route('admin.updates.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Update Center</a>
            <a href="{{ route('admin.backups.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Backup & Restore</a>
            <a href="{{ route('admin.audit.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Audit Logs</a>
            <a href="{{ route('admin.institution-settings.edit') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Institution &amp; Region</a>
            <a href="{{ route('admin.appearance.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Design &amp; Appearance</a>

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Marketing, Growth & SEO</div>
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('meta-pixel') && Route::has('plugins.meta_pixel.index'))
                <a href="{{ route('plugins.meta_pixel.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Facebook Meta Pixel</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('google-console') && Route::has('plugins.google_console.index'))
                <a href="{{ route('plugins.google_console.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Google Console & GA4</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('admin-boosting') && Route::has('plugins.boost.index'))
                <a href="{{ route('plugins.boost.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Admin Boosting</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('visitor-popup-ads') && Route::has('plugins.popups.index'))
                <a href="{{ route('plugins.popups.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Visitor Pop-Up Ads</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('seo-manager') && Route::has('plugins.seo.index'))
                <a href="{{ route('plugins.seo.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">SEO & Sitemap</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('sms-gateway') && Route::has('plugins.sms.index'))
                <a href="{{ route('plugins.sms.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">SMS Marketing</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('marketing-management') && Route::has('plugins.marketing.index'))
                <a href="{{ route('plugins.marketing.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Marketing &amp; Coupons</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('gmail-marketing') && Route::has('plugins.gmail.index'))
                <a href="{{ route('plugins.gmail.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Gmail Marketing</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('email-notifications') && Route::has('plugins.email_notifications.index'))
                <a href="{{ route('plugins.email_notifications.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Email Notifications</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('notice-board') && Route::has('plugins.notices.index'))
                <a href="{{ route('plugins.notices.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Notice Board</a>
            @endif

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Academic &amp; Operations</div>
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('branch-management') && Route::has('plugins.branches.index'))
                <a href="{{ route('plugins.branches.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Branches</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('admission-management') && Route::has('plugins.admissions.index'))
                <a href="{{ route('plugins.admissions.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Admissions</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-management') && Route::has('plugins.students.index'))
                <a href="{{ route('plugins.students.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Students</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('teacher-management') && Route::has('plugins.teachers.index'))
                <a href="{{ route('plugins.teachers.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Teachers</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('guardian-management') && Route::has('plugins.guardians.index'))
                <a href="{{ route('plugins.guardians.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Guardians</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management') && Route::has('plugins.courses.index'))
                <a href="{{ route('plugins.courses.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Courses</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management') && Route::has('plugins.enrollments.index'))
                <a href="{{ route('plugins.enrollments.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Course Enrollments</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('class-management') && Route::has('plugins.classes.index'))
                <a href="{{ route('plugins.classes.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Classes &amp; Routines</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('batch-management') && Route::has('plugins.batches.index'))
                <a href="{{ route('plugins.batches.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Batches &amp; Seats</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('exam-management') && Route::has('plugins.exams.index'))
                <a href="{{ route('plugins.exams.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Exams &amp; Bank</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('attendance-management') && Route::has('plugins.attendance.index'))
                <a href="{{ route('plugins.attendance.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Attendance</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('certificate-management') && Route::has('plugins.certificates.index'))
                <a href="{{ route('plugins.certificates.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Certificates</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('library-management') && Route::has('plugins.library.index'))
                <a href="{{ route('plugins.library.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Library &amp; Loans</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('hr-staff-management') && Route::has('plugins.hr.index'))
                <a href="{{ route('plugins.hr.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">HR &amp; Payroll</a>
            @endif

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Finance &amp; Gateways</div>
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('fees-management') && Route::has('plugins.fees.index'))
                <a href="{{ route('plugins.fees.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Fees &amp; Invoices</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('payment-management') && Route::has('plugins.payments.index'))
                <a href="{{ route('plugins.payments.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Payments &amp; Ledger</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('universal-payment') && Route::has('plugins.universal_payment.index'))
                <a href="{{ route('plugins.universal_payment.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Universal Payment</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('gateway-stripe') && Route::has('plugins.gateway_stripe.index'))
                <a href="{{ route('plugins.gateway_stripe.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Stripe Gateway</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('gateway-paypal') && Route::has('plugins.gateway_paypal.index'))
                <a href="{{ route('plugins.gateway_paypal.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">PayPal Gateway</a>
            @endif

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">CMS, Builders &amp; AI</div>
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('media-library') && Route::has('plugins.media.index'))
                <a href="{{ route('plugins.media.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Media Library</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('storefront-catalog') && Route::has('plugins.storefront.index'))
                <a href="{{ route('plugins.storefront.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Products &amp; E-books</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('cms-website') && Route::has('plugins.cms.index'))
                <a href="{{ route('plugins.cms.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Website CMS</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('page-builder') && Route::has('plugins.page_builder.index'))
                <a href="{{ route('plugins.page_builder.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Page Builder</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('slider-builder') && Route::has('plugins.slider_builder.index'))
                <a href="{{ route('plugins.slider_builder.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Slider Builder</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('carousel-builder') && Route::has('plugins.carousel_builder.index'))
                <a href="{{ route('plugins.carousel_builder.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Carousel Builder</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('notification-center') && Route::has('plugins.notifications.index'))
                <a href="{{ route('plugins.notifications.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Notification Center</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('reports-analytics') && Route::has('plugins.reports.index'))
                <a href="{{ route('plugins.reports.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Reports &amp; Health</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('mobile-app-api') && Route::has('plugins.mobile_app.index'))
                <a href="{{ route('plugins.mobile_app.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Mobile App (iOS &amp; Android)</a>
            @endif
            @if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('role-ai-assistant') && Route::has('plugins.ai.index'))
                <a href="{{ route('plugins.ai.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Panel AI Assistant</a>
            @endif

        </nav>
        <div class="p-3 border-t border-slate-800">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full text-left px-3 py-2 rounded-lg text-rose-300 hover:bg-slate-800 text-sm">Sign Out</button>
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
