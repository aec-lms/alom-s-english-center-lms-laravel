<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel — AEC LMS')</title>
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
    <aside class="w-64 bg-[#022233] text-slate-200 flex flex-col shrink-0 border-r border-white/10">
        <div class="px-5 py-4 border-b border-white/10 bg-[#022D41]">
            <div class="text-sm font-extrabold tracking-tight text-white">AEC LMS Core</div>
            <div class="text-[11px] text-[#38BDF8] font-medium">Core + Independent Plugins</div>
        </div>
        @inject('pluginManager', 'App\Core\Plugins\PluginManager')
        <nav class="flex-1 p-3 space-y-1 text-sm overflow-y-auto">
            <div class="px-3 pt-1 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Protected Core</div>
            <a href="{{ route('admin.dashboard') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Dashboard</a>
            <a href="{{ route('admin.plugins.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Plugin Center</a>
            <a href="{{ route('admin.users.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Users & Roles</a>
            <a href="{{ route('admin.updates.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Update Center</a>
            <a href="{{ route('admin.backups.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Backup & Restore</a>
            <a href="{{ route('admin.audit.index') }}" class="block px-3 py-2 rounded-lg hover:bg-white/10 transition-colors">Audit Logs</a>

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Marketing, Growth & SEO</div>
            @if ($pluginManager->isEnabled('meta-pixel') && Route::has('plugins.meta_pixel.index'))
                <a href="{{ route('plugins.meta_pixel.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Facebook Meta Pixel</a>
            @endif
            @if ($pluginManager->isEnabled('google-console') && Route::has('plugins.google_console.index'))
                <a href="{{ route('plugins.google_console.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Google Console & GA4</a>
            @endif
            @if ($pluginManager->isEnabled('admin-boosting') && Route::has('plugins.boost.index'))
                <a href="{{ route('plugins.boost.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Admin Boosting</a>
            @endif
            @if ($pluginManager->isEnabled('visitor-popup-ads') && Route::has('plugins.popups.index'))
                <a href="{{ route('plugins.popups.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Visitor Pop-Up Ads</a>
            @endif
            @if ($pluginManager->isEnabled('seo-manager') && Route::has('plugins.seo.index'))
                <a href="{{ route('plugins.seo.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">SEO & Sitemap</a>
            @endif
            @if ($pluginManager->isEnabled('sms-gateway') && Route::has('plugins.sms.index'))
                <a href="{{ route('plugins.sms.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">SMS Marketing</a>
            @endif
            @if ($pluginManager->isEnabled('marketing-management') && Route::has('plugins.marketing.index'))
                <a href="{{ route('plugins.marketing.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Marketing &amp; Coupons</a>
            @endif
            @if ($pluginManager->isEnabled('gmail-marketing') && Route::has('plugins.gmail.index'))
                <a href="{{ route('plugins.gmail.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Gmail Marketing</a>
            @endif
            @if ($pluginManager->isEnabled('email-notifications') && Route::has('plugins.email_notifications.index'))
                <a href="{{ route('plugins.email_notifications.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Email Notifications</a>
            @endif
            @if ($pluginManager->isEnabled('notice-board') && Route::has('plugins.notices.index'))
                <a href="{{ route('plugins.notices.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Notice Board</a>
            @endif

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Academic &amp; Operations</div>
            @if ($pluginManager->isEnabled('branch-management') && Route::has('plugins.branches.index'))
                <a href="{{ route('plugins.branches.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Branches</a>
            @endif
            @if ($pluginManager->isEnabled('admission-management') && Route::has('plugins.admissions.index'))
                <a href="{{ route('plugins.admissions.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Admissions</a>
            @endif
            @if ($pluginManager->isEnabled('student-management') && Route::has('plugins.students.index'))
                <a href="{{ route('plugins.students.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Students</a>
            @endif
            @if ($pluginManager->isEnabled('teacher-management') && Route::has('plugins.teachers.index'))
                <a href="{{ route('plugins.teachers.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Teachers</a>
            @endif
            @if ($pluginManager->isEnabled('guardian-management') && Route::has('plugins.guardians.index'))
                <a href="{{ route('plugins.guardians.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Guardians</a>
            @endif
            @if ($pluginManager->isEnabled('course-management') && Route::has('plugins.courses.index'))
                <a href="{{ route('plugins.courses.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Courses</a>
            @endif
            @if ($pluginManager->isEnabled('course-management') && Route::has('plugins.enrollments.index'))
                <a href="{{ route('plugins.enrollments.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Course Enrollments</a>
            @endif
            @if ($pluginManager->isEnabled('class-management') && Route::has('plugins.classes.index'))
                <a href="{{ route('plugins.classes.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Classes &amp; Routines</a>
            @endif
            @if ($pluginManager->isEnabled('batch-management') && Route::has('plugins.batches.index'))
                <a href="{{ route('plugins.batches.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Batches &amp; Seats</a>
            @endif
            @if ($pluginManager->isEnabled('exam-management') && Route::has('plugins.exams.index'))
                <a href="{{ route('plugins.exams.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Exams &amp; Bank</a>
            @endif
            @if ($pluginManager->isEnabled('attendance-management') && Route::has('plugins.attendance.index'))
                <a href="{{ route('plugins.attendance.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Attendance</a>
            @endif
            @if ($pluginManager->isEnabled('certificate-management') && Route::has('plugins.certificates.index'))
                <a href="{{ route('plugins.certificates.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Certificates</a>
            @endif
            @if ($pluginManager->isEnabled('library-management') && Route::has('plugins.library.index'))
                <a href="{{ route('plugins.library.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Library &amp; Loans</a>
            @endif
            @if ($pluginManager->isEnabled('hr-staff-management') && Route::has('plugins.hr.index'))
                <a href="{{ route('plugins.hr.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">HR &amp; Payroll</a>
            @endif

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Finance &amp; Gateways</div>
            @if ($pluginManager->isEnabled('fees-management') && Route::has('plugins.fees.index'))
                <a href="{{ route('plugins.fees.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Fees &amp; Invoices</a>
            @endif
            @if ($pluginManager->isEnabled('payment-management') && Route::has('plugins.payments.index'))
                <a href="{{ route('plugins.payments.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Payments &amp; Ledger</a>
            @endif
            @if ($pluginManager->isEnabled('universal-payment') && Route::has('plugins.universal_payment.index'))
                <a href="{{ route('plugins.universal_payment.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Universal Payment</a>
            @endif
            @if ($pluginManager->isEnabled('gateway-stripe') && Route::has('plugins.gateway_stripe.index'))
                <a href="{{ route('plugins.gateway_stripe.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Stripe Gateway</a>
            @endif
            @if ($pluginManager->isEnabled('gateway-paypal') && Route::has('plugins.gateway_paypal.index'))
                <a href="{{ route('plugins.gateway_paypal.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">PayPal Gateway</a>
            @endif

            <div class="px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">CMS, Builders &amp; AI</div>
            @if ($pluginManager->isEnabled('cms-website') && Route::has('plugins.cms.index'))
                <a href="{{ route('plugins.cms.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Website CMS</a>
            @endif
            @if ($pluginManager->isEnabled('page-builder') && Route::has('plugins.page_builder.index'))
                <a href="{{ route('plugins.page_builder.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Page Builder</a>
            @endif
            @if ($pluginManager->isEnabled('slider-builder') && Route::has('plugins.slider_builder.index'))
                <a href="{{ route('plugins.slider_builder.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Slider Builder</a>
            @endif
            @if ($pluginManager->isEnabled('carousel-builder') && Route::has('plugins.carousel_builder.index'))
                <a href="{{ route('plugins.carousel_builder.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Carousel Builder</a>
            @endif
            @if ($pluginManager->isEnabled('notification-center') && Route::has('plugins.notifications.index'))
                <a href="{{ route('plugins.notifications.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Notification Center</a>
            @endif
            @if ($pluginManager->isEnabled('reports-analytics') && Route::has('plugins.reports.index'))
                <a href="{{ route('plugins.reports.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Reports &amp; Health</a>
            @endif
            @if ($pluginManager->isEnabled('mobile-app-api') && Route::has('plugins.mobile_app.index'))
                <a href="{{ route('plugins.mobile_app.index') }}" class="block px-3 py-1.5 rounded-lg hover:bg-white/10 text-xs">Mobile App (iOS &amp; Android)</a>
            @endif
            @if ($pluginManager->isEnabled('role-ai-assistant') && Route::has('plugins.ai.index'))
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
    <main class="flex-1 p-6 overflow-y-auto">
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

@if ($pluginManager->isEnabled('role-ai-assistant') && Route::has('plugins.ai.ask'))
    @includeIf('role-ai-assistant::partials.widget')
@endif
</body>
</html>
