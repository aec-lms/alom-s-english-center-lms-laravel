<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (!empty($siteIdentity['favicon_url']))<link rel="icon" href="{{ $siteIdentity['favicon_url'] }}">@endif
    @inject('pluginManager', 'App\Core\Plugins\PluginManager')
    @php
        $seoEnabled = $pluginManager->isEnabled('seo-manager');
        $gscEnabled = $pluginManager->isEnabled('google-console');
        $pixelEnabled = $pluginManager->isEnabled('meta-pixel');
        $popupEnabled = $pluginManager->isEnabled('visitor-popup-ads');

        $seoTitle = null;
        $seoDescription = null;
        $seoKeywords = null;
        $seoCanonical = null;
        $seoRobots = 'index,follow';

        if ($seoEnabled) {
            $seoTitle = $pluginManager->getSetting('seo-manager', 'site_title_seo');
            $seoDescription = $pluginManager->getSetting('seo-manager', 'site_meta_description');
            $seoCanonical = $pluginManager->getSetting('seo-manager', 'canonical_url');
            if (\Illuminate\Support\Facades\Schema::hasTable('seo_meta_rules')) {
                $routePath = '/' . ltrim(request()->path(), '/');
                if ($routePath === '//') { $routePath = '/'; }
                $rule = \Illuminate\Support\Facades\DB::table('seo_meta_rules')->where('route_path', $routePath)->first();
                if ($rule) {
                    $seoTitle = $rule->meta_title ?: $seoTitle;
                    $seoDescription = $rule->meta_description ?: $seoDescription;
                    $seoKeywords = $rule->meta_keywords;
                    $seoCanonical = $rule->canonical_url ?: $seoCanonical;
                    $seoRobots = $rule->robots_directive ?: $seoRobots;
                }
            }
        }
    @endphp

    @php
        $metaDescription = trim((string) $__env->yieldContent('meta_description', $seoDescription));
    @endphp
    <title>@yield('title', $seoTitle ?: config('app.name', "Alom's English Center — AEC LMS"))</title>
    @if ($metaDescription !== '')
        <meta name="description" content="{{ $metaDescription }}">
    @endif
    @if ($seoKeywords)
        <meta name="keywords" content="{{ $seoKeywords }}">
    @endif
    @if ($seoCanonical)
        <link rel="canonical" href="{{ $seoCanonical }}">
    @endif
    @if ($seoEnabled)
        <meta name="robots" content="{{ $seoRobots }}">
        <meta property="og:title" content="{{ $seoTitle ?: config('app.name', 'AEC LMS') }}">
        @if ($metaDescription !== '')
            <meta property="og:description" content="{{ $metaDescription }}">
        @endif
    @endif

    @if ($gscEnabled)
        @php
            $gscToken = $pluginManager->getSetting('google-console', 'gsc_verification_token');
            $ga4Id = $pluginManager->getSetting('google-console', 'ga4_measurement_id');
            $gtmId = $pluginManager->getSetting('google-console', 'gtm_container_id');
            $autoInject = ($pluginManager->getSetting('google-console', 'auto_inject', '1') ?? '1') === '1';
        @endphp
        @if ($gscToken)
            <meta name="google-site-verification" content="{{ $gscToken }}">
        @endif
        @if ($autoInject && $ga4Id)
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($ga4Id) }}"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());
                gtag('config', @json($ga4Id));
            </script>
        @endif
        @if ($autoInject && $gtmId)
            <script>
                (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
                var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
                j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
                })(window,document,'script','dataLayer',@json($gtmId));
            </script>
        @endif
    @endif

    @if ($pixelEnabled)
        @php $metaPixelId = $pluginManager->getSetting('meta-pixel', 'pixel_id'); @endphp
        @if ($metaPixelId)
            <script>
                !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
                n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
                t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
                document,'script','https://connect.facebook.net/en_US/fbevents.js');
                fbq('init', @json($metaPixelId));
                fbq('track', 'PageView');
            </script>
        @endif
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    @inject('themeManager', 'App\\Core\\Appearance\\ThemeManager')
    <style>
        :root { {!! $themeManager->cssVariables() !!}; {!! $themeManager->cssDesignVariables() !!} }
        body { font-family:var(--aec-font-family);font-size:var(--aec-font-size); }
        .max-w-7xl,.max-w-6xl { max-width:min(var(--aec-content-width),calc(100% - 2rem))!important; }
        h1,h2,h3,h4,h5,h6 { font-weight:var(--aec-heading-weight); }
        .rounded-xl { border-radius:var(--aec-radius); }
        .space-y-6 > :not([hidden]) ~ :not([hidden]) { margin-top:calc(1.5rem * var(--aec-density)); }
        body {
            background-color:var(--aec-page-bg)!important;
            color:var(--aec-text)!important;
            font-family:var(--aec-font-family), -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;font-size:var(--aec-font-size);
        }
        .bg-white { background-color:var(--aec-surface)!important; }
        .bg-slate-50 { background-color:var(--aec-surface-alt)!important; }
        .text-slate-900 { color:var(--aec-text)!important; }
        .text-slate-500,.text-slate-600 { color:var(--aec-muted)!important; }
        .border-slate-200,.border-slate-300 { border-color:var(--aec-border)!important; }
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
        .text-blue-600,.text-blue-700 { color:var(--aec-link)!important; }
        input:not([type=color]),select,textarea { background-color:var(--aec-input-bg);color:var(--aec-input-text);border-color:var(--aec-border); }
        @keyframes marqueeLeft {
            0% { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-50%, 0, 0); }
        }
        @keyframes marqueeRight {
            0% { transform: translate3d(-50%, 0, 0); }
            100% { transform: translate3d(0, 0, 0); }
        }
        .animate-marquee-left {
            display: flex;
            width: max-content;
            animation: marqueeLeft 42s linear infinite;
            will-change: transform;
        }
        .animate-marquee-right {
            display: flex;
            width: max-content;
            animation: marqueeRight 46s linear infinite;
            will-change: transform;
        }
        .marquee-wrapper:hover .animate-marquee-left,
        .marquee-wrapper:hover .animate-marquee-right {
            animation-play-state: paused;
        }
        @media (prefers-reduced-motion: reduce) {
            .animate-marquee-left,
            .animate-marquee-right {
                animation: none;
            }
        }
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
<body class="bg-slate-50 text-slate-900 antialiased">
    @if ($pluginManager->isEnabled('al-branding-loader'))
        @include('al-branding-loader::components.loading-screen')
    @endif
    @yield('content')

    @if ($popupEnabled && \Illuminate\Support\Facades\Schema::hasTable('visitor_popups'))
        @php
            $activePopup = \Illuminate\Support\Facades\DB::table('visitor_popups')->where('is_active', true)->latest('id')->first();
        @endphp
        @if ($activePopup)
            <div id="aec-visitor-popup" class="hidden fixed inset-0 z-50 bg-slate-950/65 backdrop-blur-xs flex items-center justify-center p-4">
                <div class="bg-white rounded-3xl max-w-md w-full overflow-hidden shadow-2xl border border-slate-200 relative">
                    <button type="button" id="aec-popup-close" aria-label="Close ad" class="absolute top-3 right-3 w-8 h-8 rounded-full bg-slate-900/80 text-white text-xs font-bold flex items-center justify-center hover:bg-slate-900">✕</button>
                    @if ($activePopup->image_path)
                        <img src="{{ $activePopup->image_path }}" alt="{{ $activePopup->title }}" class="w-full h-48 object-cover">
                    @endif
                    <div class="p-6 space-y-3 text-center">
                        @if ($activePopup->badge_text)
                            <span class="inline-block px-3 py-1 rounded-full bg-blue-50 text-[#0057FF] text-[10px] font-extrabold uppercase tracking-wider">{{ $activePopup->badge_text }}</span>
                        @endif
                        <h3 class="text-xl font-extrabold text-slate-900">{{ $activePopup->title }}</h3>
                        @if ($activePopup->description)
                            <p class="text-xs text-slate-600 leading-relaxed">{{ $activePopup->description }}</p>
                        @endif
                        <a href="{{ $activePopup->cta_url ?: '/courses' }}" id="aec-popup-cta" class="inline-flex items-center justify-center w-full py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold transition-colors">
                            {{ $activePopup->cta_text ?: 'Claim Offer' }}
                        </a>
                    </div>
                </div>
            </div>
            <script>
            (function () {
                var modal = document.getElementById('aec-visitor-popup');
                var closeBtn = document.getElementById('aec-popup-close');
                var cta = document.getElementById('aec-popup-cta');
                if (!modal) return;
                var freq = @json($activePopup->frequency ?? 'once_per_session');
                var storageKey = 'aec_popup_seen_' + @json($activePopup->id);
                if (freq === 'once_per_session' && sessionStorage.getItem(storageKey)) return;
                if (freq === 'once_per_day' && localStorage.getItem(storageKey) === new Date().toISOString().slice(0, 10)) return;
                setTimeout(function () {
                    modal.classList.remove('hidden');
                    if (freq === 'once_per_session') sessionStorage.setItem(storageKey, '1');
                    if (freq === 'once_per_day') localStorage.setItem(storageKey, new Date().toISOString().slice(0, 10));
                }, {{ max(0, (int) ($activePopup->delay_seconds ?? 3)) }} * 1000);
                closeBtn && closeBtn.addEventListener('click', function () { modal.classList.add('hidden'); });
                cta && cta.addEventListener('click', function () {
                    fetch('/popup-ads/{{ $activePopup->id }}/click', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '' }
                    });
                });
            })();
            </script>
        @endif
    @endif
</body>
</html>
