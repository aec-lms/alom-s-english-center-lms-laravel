@php
    $headerLayout = app(\App\Core\Appearance\SiteLayoutManager::class)->header();
    $navLinks = app(\App\Core\Appearance\SiteNavigationManager::class)->items('primary');
    if (auth()->check() && auth()->user()->hasRole('student') && app(\App\Core\Plugins\PluginManager::class)->isEnabled('student-tracking') && Route::has('plugins.student-tracking.mine')) {
        $navLinks[] = [
            'label' => 'My Activity',
            'url' => route('plugins.student-tracking.mine'),
            'active' => request()->routeIs('plugins.student-tracking.mine'),
            'external' => false,
        ];
    }
    if (app(\App\Core\Plugins\PluginManager::class)->isEnabled('learning-paths')
        && Route::has('learning-paths.catalog')) {
        $navLinks[] = [
            'label' => 'Learning Paths',
            'url' => route('learning-paths.catalog'),
            'active' => request()->routeIs('learning-paths.catalog', 'learning-paths.show'),
            'external' => false,
        ];
    }
    if (auth()->check() && auth()->user()->hasRole('student')
        && app(\App\Core\Plugins\PluginManager::class)->isEnabled('learning-paths')
        && Route::has('learning-paths.mine')) {
        $navLinks[] = [
            'label' => 'My Learning Paths',
            'url' => route('learning-paths.mine'),
            'active' => request()->routeIs('learning-paths.mine'),
            'external' => false,
        ];
    }
    if (auth()->check() && auth()->user()->hasRole('student')
        && app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-wishlist')
        && app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-management')
        && Route::has('plugins.course-wishlist.index')) {
        $navLinks[] = [
            'label' => 'My Wishlist',
            'url' => route('plugins.course-wishlist.index'),
            'active' => request()->routeIs('plugins.course-wishlist.*'),
            'external' => false,
        ];
    }
    if (auth()->check() && auth()->user()->hasRole('guardian')
        && app(\App\Core\Plugins\PluginManager::class)->isEnabled('guardian-portal')
        && Route::has('plugins.guardian-portal.dashboard')) {
        $navLinks[] = [
            'label' => 'Guardian Portal',
            'url' => route('plugins.guardian-portal.dashboard'),
            'active' => request()->routeIs('plugins.guardian-portal.*'),
            'external' => false,
        ];
    }
    if (auth()->check() && auth()->user()->hasRole('student')
        && app(\App\Core\Plugins\PluginManager::class)->isEnabled('commerce-cart')
        && Route::has('plugins.commerce_cart.index')) {
        $navLinks[] = [
            'label' => 'Learning Services',
            'url' => route('plugins.commerce_cart.services'),
            'active' => request()->routeIs('plugins.commerce_cart.services'),
            'external' => false,
        ];
        $navLinks[] = [
            'label' => 'Shopping Cart',
            'url' => route('plugins.commerce_cart.index'),
            'active' => request()->routeIs('plugins.commerce_cart.*'),
            'external' => false,
        ];
    }
@endphp

<header data-header-style="{{ $headerLayout['style'] }}" class="w-full bg-white border-b border-slate-200/80 {{ $headerLayout['sticky'] ? 'sticky top-0' : 'relative' }} z-40 shadow-2xs">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 min-h-16 flex items-center {{ $headerLayout['style'] === 'centered' ? 'justify-center' : 'justify-between' }} {{ $headerLayout['style'] === 'compact' ? 'gap-3' : 'gap-6' }}">
        @if ($headerLayout['sections']['brand']['visible'])
        <a href="{{ route('home') }}" style="order:{{ $headerLayout['sections']['brand']['order'] }}" class="flex items-center gap-2.5 whitespace-nowrap shrink-0">
            @if (empty($siteIdentity['logo_url']))
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-9 h-9 shrink-0" aria-hidden="true">
                <circle cx="32" cy="32" r="24" stroke="#CBD5E1" stroke-width="1.6" />
                <path d="M13.5 16.5A26.5 26.5 0 0 1 56.5 23.5" stroke="#F59E0B" stroke-width="3.2" stroke-linecap="round" />
                <path d="M56.5 40.5A26.5 26.5 0 0 1 11.2 48.8" stroke="#F59E0B" stroke-width="3.2" stroke-linecap="round" />
                <path d="M7.5 39.5A26.5 26.5 0 0 1 10.5 20" stroke="#F59E0B" stroke-width="3.2" stroke-linecap="round" />
                <path d="M13 46L21.5 18H36.5L33.5 23H25L20 46H13Z" fill="#1E293B" />
                <path d="M23.5 28L26.5 46H32.5L28.5 25L23.5 28Z" fill="#334155" />
                <path d="M27.5 31.5H42.5V36.5H27.5V31.5Z" fill="#1E293B" />
                <path d="M47 24.5C44.8 20.5 40.6 18 35.8 18C28.7 18 23.2 24.1 23.2 32C23.2 39.9 28.7 46 35.8 46C40.7 46 45 43.3 47.1 39.2L41.8 36.8C40.5 39.2 38.3 40.5 35.8 40.5C31.9 40.5 29.1 36.9 29.1 32C29.1 27.1 31.9 23.5 35.8 23.5C38.3 23.5 40.4 24.8 41.7 27.1L47 24.5Z" fill="#F59E0B" />
                <path d="M44 29.5C46 28.8 48.2 29 49.5 30.2C50.8 29 53 28.8 55 29.5V36.5C53 35.8 50.8 36 49.5 37.2C48.2 36 46 35.8 44 36.5V29.5Z" fill="#FFFFFF" stroke="#64748B" stroke-width="1.3" stroke-linejoin="round" />
                <path d="M49.5 30.2V37.2" stroke="#64748B" stroke-width="1.2" />
                    </svg>
                    @else
                <img src="{{ $siteIdentity['logo_url'] }}" alt="{{ config('app.name') }} logo" class="h-9 max-w-36 shrink-0 object-contain">
            @endif
            <span class="leading-[1.06]">
                <span class="block text-[13px] sm:text-sm font-black tracking-tight text-slate-900">{{ config('app.name') }}</span>
                <span class="block text-[10px] sm:text-[11px] font-extrabold tracking-tight text-slate-800">{{ ($siteIdentity['tagline'] ?? null) === 'Learn English with confidence' ? __('Learn English with confidence') : ($siteIdentity['tagline'] ?? '') }}</span>
            </span>
        </a>
        @endif

        @if ($headerLayout['sections']['navigation']['visible'])
        <nav style="order:{{ $headerLayout['sections']['navigation']['order'] }}" class="hidden lg:flex items-center gap-7 text-[13px] font-medium text-slate-600">
            @foreach ($navLinks as $item)
                <a
                    href="{{ $item['url'] }}" @if (!empty($item['external'])) target="_blank" rel="noopener noreferrer" @endif
                    class="whitespace-nowrap shrink-0 py-1.5 transition-colors {{ $item['active'] ? 'text-[#0284C7] font-semibold' : 'hover:text-slate-900' }}"
                >
                    {{ __($item['label']) }}
                </a>
            @endforeach
        </nav>
        @endif

        @if ($headerLayout['sections']['actions']['visible'])
        <div style="order:{{ $headerLayout['sections']['actions']['order'] }}" class="flex items-center gap-2.5 shrink-0">
            @include('public.partials.language-switcher')
            @auth
                <a href="{{ route('portal.dashboard') }}" class="px-6 py-2 rounded-full bg-[#EEF2FF] hover:bg-[#E0E7FF] border border-[#E0E7FF]/80 text-[#3B82F6] text-xs sm:text-[13px] font-semibold whitespace-nowrap transition-colors">
                    {{ __('My Dashboard') }}
                </a>
            @else
                <a href="{{ route('login') }}" class="px-6 py-2 rounded-full bg-[#EEF2FF] hover:bg-[#E0E7FF] border border-[#E0E7FF]/80 text-[#3B82F6] text-xs sm:text-[13px] font-semibold whitespace-nowrap transition-colors">
                    {{ __('Login') }}
                </a>
            @endauth

            <button
                type="button"
                id="public-mobile-menu-toggle"
                aria-label="{{ __('Toggle navigation menu') }}"
                class="lg:hidden p-2 text-slate-600 hover:text-slate-900 rounded-lg hover:bg-slate-100 transition-colors"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
        @endif
    </div>

    @if ($headerLayout['sections']['navigation']['visible'])
    <div id="public-mobile-menu" class="hidden lg:hidden bg-white border-t border-slate-200 px-4 py-3 space-y-1 shadow-md">
        @foreach ($navLinks as $item)
            <a
                href="{{ $item['url'] }}" @if (!empty($item['external'])) target="_blank" rel="noopener noreferrer" @endif
                class="block px-3 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $item['active'] ? 'bg-[#EEF2FF] text-[#0284C7] font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-[#0284C7]' }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
        <div class="px-3 pt-3">@include('public.partials.language-switcher')</div>
    </div>
    @endif
</header>

<script>
(() => {
    const btn = document.getElementById('public-mobile-menu-toggle');
    const menu = document.getElementById('public-mobile-menu');
    if (btn && menu) {
        btn.addEventListener('click', () => menu.classList.toggle('hidden'));
    }
})();
</script>
