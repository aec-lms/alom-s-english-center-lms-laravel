@extends('layouts.app')
@section('title', "Alom's English Center — AEC LMS")
@section('content')
@php
    $pluginManager = app(\App\Core\Plugins\PluginManager::class);
    $showSslcommerzStrip = $pluginManager->isEnabled('gateway-sslcommerz')
        && \Illuminate\Support\Facades\Schema::hasTable('plugin_settings')
        && filled($pluginManager->getSetting('gateway-sslcommerz', 'store_id'))
        && filled($pluginManager->getSetting('gateway-sslcommerz', 'store_password'));
    $iosAppUrl = trim((string) ($homepage['app_ios_url'] ?? ''));
    $androidAppUrl = trim((string) ($homepage['app_android_url'] ?? ''));
    $iosAppAvailable = filter_var($iosAppUrl, FILTER_VALIDATE_URL)
        && parse_url($iosAppUrl, PHP_URL_HOST) === 'apps.apple.com';
    $androidAppAvailable = filter_var($androidAppUrl, FILTER_VALIDATE_URL)
        && parse_url($androidAppUrl, PHP_URL_HOST) === 'play.google.com';

    $heroSlides = $sliders->isNotEmpty() ? $sliders->map(fn ($s) => [
        'title' => $s->title,
        'subtitle' => $s->subtitle ?? '',
        'image' => is_string($s->image_path) && preg_match('~^/(?!/)(?!.*\\.\\.)[A-Za-z0-9/_\\-.]+$~', $s->image_path) ? $s->image_path : asset('assets/images/hero_educator_studio_1794552233836.jpg'),
        'cta_url' => is_string($s->cta_url) && preg_match('~^(https?://\\S+|/(?!/)\\S*|#[A-Za-z][A-Za-z0-9_-]*)$~i', $s->cta_url) ? $s->cta_url : '#learning-paths',
    ])->values()->all() : [
        [
            'title' => 'IELTS & English All in One',
            'subtitle' => 'Basic to Pro · Structured Live & Recorded Batches',
            'image' => asset('assets/images/hero_educator_studio_1794552233836.jpg'),
            'cta_url' => '#learning-paths',
        ],
        [
            'title' => 'IELTS Academic & GT Masterclass',
            'subtitle' => 'Target Band 8.0+ · Weekly Mock Tests & Writing Review',
            'image' => asset('assets/images/course_ielts_masterclass_1794552235026.jpg'),
            'cta_url' => '#learning-paths',
        ],
        [
            'title' => 'Spoken English & Fluency Bootcamp',
            'subtitle' => 'Phonetics, Pronunciation & Daily Speaking Practice',
            'image' => asset('assets/images/course_spoken_fluency_1794552235255.jpg'),
            'cta_url' => '#learning-paths',
        ],
    ];

    $learningPaths = $homepage['learning_paths'] ?? [
        ['title' => 'Academic Courses', 'desc' => 'Comprehensive academic preparation for all levels', 'bg' => '#D6E9FF', 'border' => '#BBD8FF', 'accent' => '#1D63FF', 'abbr' => 'AC', 'url' => route('public.courses'), 'enabled' => true],
        ['title' => 'Admission Courses', 'desc' => 'Specialized courses for university & IELTS admissions', 'bg' => '#CEFCE0', 'border' => '#B2F5CD', 'accent' => '#00A647', 'abbr' => 'AD', 'url' => route('public.courses'), 'enabled' => true],
        ['title' => 'Grammar & Logic', 'desc' => 'Advanced grammar concepts and problem solving', 'bg' => '#F0DEFF', 'border' => '#E1C2FF', 'accent' => '#9333EA', 'abbr' => 'GR', 'url' => route('public.ebooks'), 'enabled' => true],
        ['title' => 'Spoken & Phonetics', 'desc' => 'Pronunciation clarity, fluency & speaking drills', 'bg' => '#FFE6C7', 'border' => '#FFD09B', 'accent' => '#F94E00', 'abbr' => 'SP', 'url' => route('public.learn'), 'enabled' => true],
        ['title' => 'Technology & Skills', 'desc' => 'Modern writing, presentation & career skills', 'bg' => '#DDE6FF', 'border' => '#C4D2FF', 'accent' => '#4F46E5', 'abbr' => 'SK', 'url' => route('public.exams'), 'enabled' => true],
        ['title' => 'Community', 'desc' => 'Join our supportive peer learning community', 'bg' => '#FFE0EE', 'border' => '#FFC4DE', 'accent' => '#EC0075', 'abbr' => 'CM', 'url' => route('public.instructors'), 'enabled' => true],
    ];

    $leftCards = $leftReviews->isNotEmpty() ? $leftReviews : collect([
        (object) ['title' => 'Mahmud Hasan', 'subtitle' => 'Weekly mock interviews and line-by-line Writing feedback helped me progress from Band 6.0 to 8.0.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Afrina Jahan', 'subtitle' => 'Every grammar concept from tense logic to free-hand composition is explained with crystal clarity.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Borhan Uddin', 'subtitle' => 'Structured lessons and daily practice drills built my spoken fluency inside two months.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Sadia Afrin', 'subtitle' => 'Clear guidance, responsive instructors, and reliable exam evaluation at every step.', 'meta' => 'Verified Student'],
    ]);

    $rightCards = $rightReviews->isNotEmpty() ? $rightReviews : collect([
        (object) ['title' => 'Zidni Islam', 'subtitle' => 'The online classroom, lecture sheets, and diagnostic exams make daily revision effortless.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Sumaiya Saima', 'subtitle' => 'Phonetics sessions and live speaking clubs removed my hesitation during interviews.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Fakhrul Islam', 'subtitle' => 'Best structured English academy with measurable progress tracking across every module.', 'meta' => 'Verified Student'],
        (object) ['title' => 'Tasannun Tarin', 'subtitle' => 'Reading strategy workshops helped me solve passage traps rapidly and accurately.', 'meta' => 'Verified Student'],
    ]);
@endphp

@include('public.partials.navbar')

@if (($homepageBoostCampaigns ?? collect())->isNotEmpty())
    <section class="bg-gradient-to-r from-[#EAF2FF] via-white to-[#E8F8FF] border-b border-sky-100 px-4 py-5">
        <div class="max-w-[1280px] mx-auto grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            @foreach ($homepageBoostCampaigns as $boostCampaign)
                <article class="relative rounded-2xl border border-blue-100 bg-white p-4 shadow-sm hover:shadow-lg transition-shadow">
                    <img src="{{ route('plugins.boost.impression', $boostCampaign) }}" alt="" width="1" height="1" class="absolute w-px h-px opacity-0 pointer-events-none" loading="lazy">
                    <div class="flex items-start justify-between gap-2">
                        <span class="inline-flex rounded-full bg-indigo-50 px-2 py-1 text-[9px] font-extrabold uppercase tracking-wide text-indigo-700">{{ $boostCampaign->badge_text ?: 'FEATURED' }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Sponsored</span>
                    </div>
                    <h2 class="mt-2 text-sm font-extrabold leading-snug text-slate-900">{{ $boostCampaign->campaign_name }}</h2>
                    <a href="{{ route('plugins.boost.click', $boostCampaign) }}" rel="nofollow" class="mt-3 inline-flex items-center gap-1 rounded-lg bg-[#0057FF] px-3 py-2 text-[11px] font-bold text-white hover:bg-blue-700">{{ $boostCampaign->cta_text ?: 'Explore Now' }} <span aria-hidden="true">→</span></a>
                </article>
            @endforeach
        </div>
    </section>
@endif


<!-- 2. DEEP TEAL HERO STAGE: 3-CARD PEEK CAROUSEL + DUAL BENTO CARDS -->
<section class="bg-gradient-to-b from-[#022D41] via-[#032638] to-[#021E2E] text-white pt-8 pb-14 px-4 sm:px-6 overflow-hidden">
    <div class="max-w-[1280px] mx-auto space-y-8">

        <!-- 3-Card Peek Promotional Carousel -->
        @if ($homepage['show_slider'] ?? true)
        <div class="flex items-center justify-center gap-4 lg:gap-6">
            <button type="button" id="hero-prev-peek" aria-label="Previous Slide" class="hidden lg:block w-[15%] h-[320px] rounded-2xl overflow-hidden border border-white/15 opacity-35 hover:opacity-60 transition-opacity shrink-0 relative bg-[#021C2B]">
                <img id="hero-prev-img" src="{{ $heroSlides[count($heroSlides) - 1]['image'] }}" alt="Previous preview" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-[#032D42]/50"></div>
            </button>

            <div class="w-full lg:w-[66%] max-w-[820px] relative rounded-2xl overflow-hidden border border-white/15 bg-[#021C2B] shadow-2xl">
                <a id="hero-main-link" href="{{ $heroSlides[0]['cta_url'] }}" class="block relative aspect-[16/9] sm:h-[380px] lg:h-[400px] w-full">
                    <img id="hero-main-img" src="{{ $heroSlides[0]['image'] }}" alt="{{ $heroSlides[0]['title'] }}" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-[#021824]/90 via-[#021824]/30 to-transparent"></div>
                    <div class="absolute inset-x-0 bottom-12 px-6 sm:px-10 text-center space-y-1.5">
                        <h1 id="hero-main-title" class="text-2xl sm:text-4xl font-extrabold tracking-tight text-white drop-shadow">
                            {{ $heroSlides[0]['title'] }}
                        </h1>
                        <p id="hero-main-subtitle" class="text-xs sm:text-sm font-semibold text-amber-300">
                            {{ $heroSlides[0]['subtitle'] }}
                        </p>
                    </div>
                </a>

                <button type="button" id="hero-prev-btn" aria-label="Previous slide" class="absolute left-4 bottom-4 w-8 h-8 rounded-full bg-slate-950/70 hover:bg-[#0284C7] text-white border border-white/25 flex items-center justify-center transition-colors">
                    &lsaquo;
                </button>
                <button type="button" id="hero-next-btn" aria-label="Next slide" class="absolute right-4 bottom-4 w-8 h-8 rounded-full bg-slate-950/70 hover:bg-[#0284C7] text-white border border-white/25 flex items-center justify-center transition-colors">
                    &rsaquo;
                </button>
                <div id="hero-dots" class="absolute bottom-5 left-1/2 -translate-x-1/2 flex items-center gap-1.5">
                    @foreach ($heroSlides as $i => $s)
                        <span data-dot="{{ $i }}" class="h-1.5 rounded-full transition-all {{ $i === 0 ? 'w-5 bg-white' : 'w-1.5 bg-white/40' }}"></span>
                    @endforeach
                </div>
            </div>

            <button type="button" id="hero-next-peek" aria-label="Next Slide" class="hidden lg:block w-[15%] h-[320px] rounded-2xl overflow-hidden border border-white/15 opacity-35 hover:opacity-60 transition-opacity shrink-0 relative bg-[#021C2B]">
                <img id="hero-next-img" src="{{ $heroSlides[1 % count($heroSlides)]['image'] }}" alt="Next preview" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-[#032D42]/50"></div>
            </button>
        </div>
        @endif

        <!-- Dual Bento Cards (Admin Controlled) -->
        @if (($homepage['show_batches'] ?? true) || ($homepage['show_stats'] ?? true))
        <div id="ongoing-batches" class="max-w-[1040px] mx-auto grid grid-cols-1 lg:grid-cols-2 gap-6">
            @if ($homepage['show_batches'] ?? true)
            <div class="rounded-2xl p-6 sm:p-7 border border-[#14B8A6]/25 shadow-xl flex flex-col justify-between space-y-5" style="background: linear-gradient(135deg, #064844 0%, #032833 100%)">
                <div class="space-y-2">
                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-white/10 border border-white/15 text-[11px] font-semibold text-[#A7F3D0]">{{ $homepage['batches_badge'] ?? 'Online Course' }}</span>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">{{ $homepage['batches_title'] ?? 'Online Batches are ongoing!' }}</h2>
                    <p class="text-xs sm:text-sm font-medium text-[#2DD4BF]">{{ $homepage['batches_subtitle'] ?? 'Book your seat now!' }}</p>
                </div>

                <div class="space-y-3">
                    <a href="{{ $homepage['batch_1_url'] ?? route('public.courses') }}" class="bg-[#0B2A35]/90 hover:bg-[#103643] border border-white/10 rounded-xl p-3.5 flex items-center gap-3.5 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-[#10B981] text-white flex items-center justify-center shrink-0 font-bold text-sm">A</div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-white truncate">{{ $homepage['batch_1_title'] ?? 'IELTS Academic & GT' }}</div>
                            <div class="text-xs text-slate-300 truncate">{{ $homepage['batch_1_desc'] ?? 'Band 7.5+ Target Preparation' }}</div>
                        </div>
                    </a>
                    <a href="{{ $homepage['batch_2_url'] ?? route('public.courses') }}" class="bg-[#0B2A35]/90 hover:bg-[#103643] border border-white/10 rounded-xl p-3.5 flex items-center gap-3.5 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-[#10B981] text-white flex items-center justify-center shrink-0 font-bold text-sm">S</div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-white truncate">{{ $homepage['batch_2_title'] ?? 'Spoken & Grammar Foundation' }}</div>
                            <div class="text-xs text-slate-300 truncate">{{ $homepage['batch_2_desc'] ?? 'Basic to Pro Live Batches' }}</div>
                        </div>
                    </a>
                </div>

                <div>
                    <a href="{{ route('public.courses') }}" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-[#2DD4BF] hover:text-[#5EEAD4]">
                        <span>{{ __('See All Courses') }}</span><span>&rarr;</span>
                    </a>
                </div>
            </div>
            @endif

            @if ($homepage['show_stats'] ?? true)
            <div class="rounded-2xl p-6 sm:p-7 border border-[#38BDF8]/25 shadow-xl flex flex-col justify-between space-y-5" style="background: linear-gradient(135deg, #073E66 0%, #03233E 100%)">
                <div class="space-y-2">
                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-white/10 border border-white/15 text-[11px] font-semibold text-[#BAE6FD]">{{ $homepage['stats_badge'] ?? 'Online Course' }}</span>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">{{ $homepage['stats_title'] ?? 'See the stats!' }}</h2>
                    <p class="text-xs sm:text-sm font-medium text-[#38BDF8]">{{ $homepage['stats_subtitle'] ?? 'The trust we are building!' }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">ST</div>
                        <div class="min-w-0">
                            <div class="homepage-stat-number text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight" data-count-up="{{ (int) $stats['students'] }}" data-count-label="Students" aria-label="{{ number_format((int) $stats['students']) }} Students">0</div>
                            <div class="text-[11px] text-slate-300">{{ __('Students') }}</div>
                        </div>
                    </div>
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">CR</div>
                        <div class="min-w-0">
                            <div class="homepage-stat-number text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight" data-count-up="{{ (int) $stats['courses'] }}" data-count-label="Courses" aria-label="{{ number_format((int) $stats['courses']) }} Courses">0</div>
                            <div class="text-[11px] text-slate-300">{{ __('Courses') }}</div>
                        </div>
                    </div>
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">TR</div>
                        <div class="min-w-0">
                            <div class="homepage-stat-number text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight" data-count-up="{{ (int) $stats['teachers'] }}" data-count-label="Teachers" aria-label="{{ number_format((int) $stats['teachers']) }} Teachers">0</div>
                            <div class="text-[11px] text-slate-300">{{ __('Teachers') }}</div>
                        </div>
                    </div>
                    <div class="bg-[#0C2B47]/90 border border-white/10 rounded-xl p-3.5 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[#0EA5E9] text-white flex items-center justify-center shrink-0 font-extrabold text-xs">VD</div>
                        <div class="min-w-0">
                            <div class="homepage-stat-number text-base sm:text-lg font-extrabold tabular-nums text-white leading-tight" data-count-up="{{ (int) $stats['videos'] }}" data-count-label="Videos" aria-label="{{ number_format((int) $stats['videos']) }} Videos">0</div>
                            <div class="text-[11px] text-slate-300">{{ __('Videos') }}</div>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="{{ route('public.courses') }}" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-[#38BDF8] hover:opacity-85">
                        <span>{{ __('See All Courses') }}</span><span>&rarr;</span>
                    </a>
                </div>
            </div>
            @endif
        </div>
        @endif

    </div>
</section>

<!-- 3. CHOOSE YOUR LEARNING PATH (ADMIN-CONTROLLED 6 PASTEL CARDS) -->
@if ($homepage['show_learning_paths'] ?? true)
<section id="learning-paths" class="py-16 px-4 sm:px-6" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
    <div class="max-w-[1160px] mx-auto space-y-10">
        <div class="text-center max-w-2xl mx-auto space-y-2.5">
            <h2 class="text-3xl sm:text-4xl font-extrabold text-[#06B6D4] tracking-tight">{{ $homepage['paths_title'] ?? 'Choose Your Learning Path' }}</h2>
            <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                {{ $homepage['paths_subtitle'] ?? 'Discover diverse courses across multiple disciplines designed to help you excel in your academic and admission journey.' }}
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($learningPaths as $lp)
                @if ($lp['enabled'] ?? true)
                <a href="{{ route('public.courses', ['category' => $lp['category'] ?? $lp['title']]) }}" data-learning-path="{{ $lp['category'] ?? $lp['title'] }}" class="rounded-2xl p-6 border transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md flex flex-col justify-between space-y-5" style="background-color: {{ $lp['bg'] ?? '#D6E9FF' }}; border-color: {{ $lp['border'] ?? '#BBD8FF' }};">
                    <div class="space-y-4">
                        <div class="w-11 h-11 rounded-xl text-white font-extrabold text-xs flex items-center justify-center shadow-xs" style="background-color: {{ $lp['accent'] ?? '#1D63FF' }}">
                            {{ $lp['abbr'] ?? 'AC' }}
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-base font-extrabold" style="color: {{ $lp['accent'] ?? '#1D63FF' }}">{{ $lp['title'] }}</h3>
                            <p class="text-xs text-slate-600 leading-relaxed">{{ $lp['desc'] }}</p>
                        </div>
                    </div>
                    <div class="flex justify-end items-center gap-1 text-xs font-bold" style="color: {{ $lp['accent'] ?? '#1D63FF' }}">
                        <span>{{ __('Explore') }}</span><span>&rsaquo;</span>
                    </div>
                </a>
                @endif
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- 4. DIGITAL PRODUCT & E-BOOK CAROUSEL (ADMIN-CONTROLLED STOREFRONT & PDF THUMBNAILS) -->
@if (($homepage['show_digital_carousel'] ?? true) && $digitalCarouselItems->isNotEmpty())
<section id="digital-products-carousel" class="py-16 px-4 sm:px-6 bg-white border-y border-slate-200/70">
    <div class="max-w-[1200px] mx-auto space-y-8">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div class="space-y-2 max-w-2xl">
                <span class="inline-block px-3 py-1 rounded-full bg-sky-50 text-[#0284C7] text-[11px] font-bold uppercase tracking-wider">{{ __('Storefront & Digital PDFs') }}</span>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">{{ $homepage['carousel_title'] ?? 'Digital Products & Study Guides' }}</h2>
                <p class="text-xs sm:text-sm text-slate-500">{{ $homepage['carousel_subtitle'] ?? "Explore ShortNote 2.0 E-Books, printed handbooks, and IELTS practice kits crafted by Alom's English Center." }}</p>
            </div>
            <div class="flex items-center gap-2.5 shrink-0">
                <button type="button" id="digital-carousel-prev" aria-label="Scroll products left" class="w-9 h-9 rounded-full border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 font-bold">&lsaquo;</button>
                <button type="button" id="digital-carousel-next" aria-label="Scroll products right" class="w-9 h-9 rounded-full border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 font-bold">&rsaquo;</button>
                <a href="{{ route('public.ebooks') }}" class="px-4 py-2 rounded-full bg-[#0284C7] hover:bg-[#0369A1] text-white text-xs font-bold">{{ $homepage['carousel_cta_text'] ?? 'Explore Catalog' }}</a>
            </div>
        </div>

        <div id="digital-carousel-track" class="flex gap-5 overflow-x-auto pb-3 scroll-smooth snap-x">
            @foreach ($digitalCarouselItems as $dc)
                <a href="{{ $dc['url'] }}" class="snap-start shrink-0 w-[260px] sm:w-[280px] rounded-2xl border border-slate-200/90 bg-slate-50/70 hover:bg-white hover:shadow-lg transition-all overflow-hidden flex flex-col justify-between group">
                    <div>
                        <div class="relative h-48 bg-slate-200 overflow-hidden">
                            <img src="{{ $dc['image'] }}" alt="{{ $dc['title'] }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                            <span class="absolute top-3 left-3 px-2.5 py-0.5 rounded-full bg-slate-950/80 text-white text-[10px] font-bold uppercase">{{ $dc['category'] }}</span>
                            <span class="absolute top-3 right-3 px-2.5 py-0.5 rounded-full {{ $dc['is_free'] ? 'bg-emerald-500 text-white' : 'bg-amber-400 text-slate-950' }} text-[10px] font-extrabold uppercase">{{ $dc['badge'] }}</span>
                        </div>
                        <div class="p-4 space-y-1">
                            <h3 class="text-sm font-extrabold text-slate-900 line-clamp-2 group-hover:text-[#0284C7]">{{ $dc['title'] }}</h3>
                            <p class="text-[11px] text-slate-500">{{ $dc['type'] === 'ebook' ? 'Protected PDF E-Book' : 'Printed Book / Study Kit' }}</p>
                        </div>
                    </div>
                    <div class="px-4 py-3 border-t border-slate-200/70 flex items-center justify-between">
                        <span class="font-mono font-extrabold text-sm text-[#0284C7]">
                            {{ $dc['is_free'] ? 'Free PDF' : '৳' . number_format($dc['price']) }}
                        </span>
                        <span class="text-xs font-bold text-slate-700 group-hover:text-[#0284C7]">View &rarr;</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- 5. WHAT OUR STUDENTS SAY (DUAL LEFT & RIGHT MARQUEE CAROUSELS) -->
@if ($homepage['show_reviews'] ?? true)
<section id="reviews" class="py-16 sm:py-20 text-white overflow-hidden" style="background: linear-gradient(180deg, #131D2D 0%, #0B1220 100%)">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 mb-10 text-center space-y-3">
        <div class="text-xs font-semibold tracking-wide text-[#38BDF8]">{{ __('Student Reviews') }}</div>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">{{ $homepage['reviews_title'] ?? 'What Our Students Say' }}</h2>
        <p class="text-xs sm:text-sm text-slate-300 max-w-xl mx-auto">
            {{ $homepage['reviews_subtitle'] ?? "Feedbacks from students who have transformed their learning journey with Alom's English Center." }}
        </p>
        <div class="pt-2 flex justify-center">
            <div class="inline-flex items-center gap-2.5 px-5 py-2 rounded-full bg-[#162238] border border-white/10 text-xs shadow-sm">
                <span class="text-amber-400 tracking-tight">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                <span class="font-bold text-white tabular-nums">5.0</span>
                <span class="text-slate-400">average rating</span>
            </div>
        </div>
    </div>

    <div class="marquee-wrapper relative overflow-hidden space-y-5">
        <div class="pointer-events-none absolute inset-y-0 left-0 w-10 sm:w-28 z-10" style="background: linear-gradient(90deg, #131D2D, transparent)"></div>
        <div class="pointer-events-none absolute inset-y-0 right-0 w-10 sm:w-28 z-10" style="background: linear-gradient(270deg, #131D2D, transparent)"></div>

        <div class="animate-marquee-left">
            @foreach ([1, 2] as $dup)
                <div class="flex gap-5 pr-5 shrink-0">
                    @foreach ($leftCards as $card)
                        <article class="w-[330px] sm:w-[360px] shrink-0 bg-[#1B2537] border border-white/10 rounded-xl p-5 flex flex-col justify-between space-y-4 shadow-lg">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-amber-400 text-xs tracking-tight">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                                    <span class="text-slate-600 font-serif text-lg leading-none">&ldquo;&rdquo;</span>
                                </div>
                                <p class="text-xs sm:text-[13px] text-slate-300 leading-relaxed italic line-clamp-4">{{ $card->subtitle }}</p>
                            </div>
                            <div class="pt-3 border-t border-white/10 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#0284C7] to-[#2563EB] text-white font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr($card->title, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-white truncate">{{ $card->title }}</div>
                                    <div class="text-[11px] text-slate-400">{{ __('Verified Student') }}</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="animate-marquee-right">
            @foreach ([1, 2] as $dup)
                <div class="flex gap-5 pr-5 shrink-0">
                    @foreach ($rightCards as $card)
                        <article class="w-[330px] sm:w-[360px] shrink-0 bg-[#1B2537] border border-white/10 rounded-xl p-5 flex flex-col justify-between space-y-4 shadow-lg">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-amber-400 text-xs tracking-tight">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                                    <span class="text-slate-600 font-serif text-lg leading-none">&ldquo;&rdquo;</span>
                                </div>
                                <p class="text-xs sm:text-[13px] text-slate-300 leading-relaxed italic line-clamp-4">{{ $card->subtitle }}</p>
                            </div>
                            <div class="pt-3 border-t border-white/10 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#10B981] to-[#0284C7] text-white font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ strtoupper(substr($card->title, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-white truncate">{{ $card->title }}</div>
                                    <div class="text-[11px] text-slate-400">{{ __('Verified Student') }}</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- 6. MOBILE APP DOWNLOAD BANNER (APPLE iOS APP STORE & GOOGLE PLAY ANDROID) -->
@if ($homepage['show_app_banner'] ?? true)
<section class="py-14 px-4 sm:px-6 bg-gradient-to-r from-[#022D41] via-[#073E66] to-[#022233] text-white border-t border-white/10">
    <div class="max-w-[1100px] mx-auto flex flex-col lg:flex-row items-center justify-between gap-8">
        <div class="space-y-3 max-w-xl text-center lg:text-left">
            <span class="inline-block px-3 py-1 rounded-full bg-white/10 border border-white/20 text-[11px] font-bold uppercase tracking-wider text-sky-300">
                {{ $homepage['app_banner_eyebrow'] ?? 'iOS & Android Mobile Learning App' }}
            </span>
            <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight">
                {{ $homepage['app_banner_title'] ?? "Take Alom's English Center Anywhere on Apple iOS & Android" }}
            </h2>
            <p class="text-xs sm:text-sm text-slate-200 leading-relaxed">
                {{ $homepage['app_banner_subtitle'] ?? 'Stream HD lectures, read downloaded PDF E-Books offline, join live Zoom classes, and track mock exam scores directly from your iPhone, iPad, or Android device.' }}
            </p>
        </div>

        <div class="flex flex-wrap items-center justify-center gap-4 shrink-0">
            @if ($iosAppAvailable)
            <a href="{{ $iosAppUrl }}" target="_blank" rel="noopener noreferrer" class="px-5 py-3 rounded-xl bg-slate-950 hover:bg-black border border-white/20 flex items-center gap-3 shadow-lg transition-colors">
                <svg class="w-6 h-6 text-white fill-current shrink-0" viewBox="0 0 24 24" aria-hidden="true"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 6.33c.67-.81 1.12-1.94.99-3.07-.96.04-2.13.64-2.82 1.45-.61.71-1.15 1.86-1.01 2.97 1.07.08 2.13-.55 2.84-1.35z"/></svg>
                <div class="text-left leading-tight">
                    <div class="text-[10px] text-slate-300">Download on the</div>
                    <div class="text-sm font-extrabold">Apple App Store</div>
                </div>
            </a>
            @else
            <span class="px-5 py-3 rounded-xl bg-slate-950/70 border border-white/20 text-sm font-bold text-white">Apple App Store — Coming Soon</span>
            @endif

            @if ($androidAppAvailable)
            <a href="{{ $androidAppUrl }}" target="_blank" rel="noopener noreferrer" class="px-5 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 border border-white/20 flex items-center gap-3 shadow-lg transition-colors">
                <svg class="w-6 h-6 text-white fill-current shrink-0" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20.5v-17c0-.59.34-1.11.84-1.35L13.69 12l-9.85 9.85c-.5-.25-.84-.76-.84-1.35zm13.81-5.38L6.05 21.34l8.49-8.49 2.27 2.27zm.91-.91l2.7-1.56c.62-.36.62-1.24 0-1.6l-2.7-1.56-2.46 2.46 2.46 2.46zM6.05 2.66l10.76 6.22-2.27 2.27-8.49-8.49z"/></svg>
                <div class="text-left leading-tight">
                    <div class="text-[10px] text-emerald-100">GET IT ON</div>
                    <div class="text-sm font-extrabold">Google Play Android</div>
                </div>
            </a>
            @else
            <span class="px-5 py-3 rounded-xl bg-emerald-700/70 border border-white/20 text-sm font-bold text-white">Google Play — Coming Soon</span>
            @endif
        </div>
    </div>
</section>
@endif

@if ($showSslcommerzStrip)
<section aria-label="Secure payment methods" class="border-t border-slate-200 bg-white py-6 px-4 sm:px-6">
    <div class="max-w-[1100px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="text-center sm:text-left">
            <p class="text-sm font-extrabold text-slate-900">{{ __('Pay securely with SSLCOMMERZ') }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ __('Secure checkout for card, mobile banking and internet banking payments.') }}</p>
        </div>
        <div class="flex flex-wrap justify-center items-center gap-2" aria-label="Supported payment channels">
            <span class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-extrabold tracking-wide text-slate-700">VISA</span>
            <span class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-extrabold text-slate-700">Mastercard</span>
            <span class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-700">{{ __('Mobile Banking') }}</span>
            <span class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-700">{{ __('Internet Banking') }}</span>
            <span class="rounded-lg bg-[#0B6B55] px-4 py-2 text-xs font-extrabold text-white">SSLCOMMERZ</span>
        </div>
    </div>
</section>
@endif

@include('public.partials.footer')


<script>
(() => {
    const counters = Array.from(document.querySelectorAll('[data-count-up]'));
    if (!counters.length) return;
    const formatter = new Intl.NumberFormat('en-US');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const animate = (element) => {
        if (element.dataset.counted === 'true') return;
        element.dataset.counted = 'true';
        const target = Math.max(0, Number.parseInt(element.dataset.countUp || '0', 10) || 0);
        if (reducedMotion || target === 0) { element.textContent = formatter.format(target); return; }
        const duration = Math.min(2400, Math.max(1100, 900 + String(target).length * 110));
        const started = performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - progress, 4);
            element.textContent = formatter.format(progress === 1 ? target : Math.min(target, Math.floor(target * eased)));
            if (progress < 1) requestAnimationFrame(tick);
            else { element.textContent = formatter.format(target); element.classList.add('homepage-stat-counted'); }
        };
        requestAnimationFrame(tick);
    };
    if (!('IntersectionObserver' in window)) { counters.forEach(animate); return; }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => { if (entry.isIntersecting) { animate(entry.target); observer.unobserve(entry.target); } });
    }, {threshold: 0.35});
    counters.forEach((counter) => observer.observe(counter));
})();
</script>
<style>
.homepage-stat-number { display:inline-block; min-width:2ch; font-variant-numeric:tabular-nums; transition:filter .35s ease, transform .35s ease; }
.homepage-stat-number.homepage-stat-counted { filter:drop-shadow(0 0 9px rgba(56,189,248,.3)); transform:translateY(-1px); }
@media (prefers-reduced-motion: reduce) { .homepage-stat-number, .homepage-stat-number.homepage-stat-counted { transition:none; transform:none; filter:none; } }
</style>

<script>
(() => {
    const track = document.getElementById('digital-carousel-track');
    document.getElementById('digital-carousel-prev')?.addEventListener('click', () => track?.scrollBy({ left: -300, behavior: 'smooth' }));
    document.getElementById('digital-carousel-next')?.addEventListener('click', () => track?.scrollBy({ left: 300, behavior: 'smooth' }));

    const slides = @json($heroSlides);
    if (!Array.isArray(slides) || slides.length < 2) return;
    let idx = 0;
    const mainImg = document.getElementById('hero-main-img');
    const prevImg = document.getElementById('hero-prev-img');
    const nextImg = document.getElementById('hero-next-img');
    const mainTitle = document.getElementById('hero-main-title');
    const mainSub = document.getElementById('hero-main-subtitle');
    const mainLink = document.getElementById('hero-main-link');
    const dots = document.querySelectorAll('#hero-dots [data-dot]');

    const render = () => {
        const prev = (idx - 1 + slides.length) % slides.length;
        const next = (idx + 1) % slides.length;
        mainImg.src = slides[idx].image;
        mainImg.alt = slides[idx].title;
        prevImg.src = slides[prev].image;
        nextImg.src = slides[next].image;
        mainTitle.textContent = slides[idx].title;
        mainSub.textContent = slides[idx].subtitle;
        if (mainLink) mainLink.href = slides[idx].cta_url || '#learning-paths';
        dots.forEach((d, i) => {
            d.className = 'h-1.5 rounded-full transition-all ' + (i === idx ? 'w-5 bg-white' : 'w-1.5 bg-white/40');
        });
    };

    const step = (delta) => { idx = (idx + delta + slides.length) % slides.length; render(); };
    document.getElementById('hero-prev-btn')?.addEventListener('click', () => step(-1));
    document.getElementById('hero-next-btn')?.addEventListener('click', () => step(1));
    document.getElementById('hero-prev-peek')?.addEventListener('click', () => step(-1));
    document.getElementById('hero-next-peek')?.addEventListener('click', () => step(1));
})();
</script>
@endsection
