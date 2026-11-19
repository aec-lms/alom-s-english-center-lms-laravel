@php
    $footerCfg = $homepage ?? [];
    if (empty($footerCfg) && \Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
        $rawCfg = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'homepage_customizer')->value('value');
        if (is_string($rawCfg) && $rawCfg !== '') {
            $footerCfg = json_decode($rawCfg, true) ?: [];
        }
    }
    $footerDesc = $footerCfg['footer_description'] ?? "Alom's English Center is a structured online and campus learning platform providing IELTS, Spoken English, and Grammar programs delivered by certified educators nationwide.";
    $footerLicense = $footerCfg['footer_license_no'] ?? 'TRAD/DNCC/044200/2026';
    $footerAddress = $siteIdentity['address'] ?: ($footerCfg['footer_address'] ?? 'Plot 14, Sector 6, Mirpur 10 Roundabout, Dhaka-1216');
    $footerPhone = $siteIdentity['contact_phone'] ?: ($footerCfg['footer_phone'] ?? '+880 1712 345678');
    $footerEmail = $siteIdentity['contact_email'] ?: ($footerCfg['footer_email'] ?? 'info@alomsenglishcenter.com');
    $footerYoutube = $footerCfg['footer_youtube_url'] ?? route('public.learn');
    $footerFacebook = $footerCfg['footer_facebook_url'] ?? route('public.courses');
    $siteFooterLayout = app(\App\Core\Appearance\SiteLayoutManager::class)->footer();
    $siteFooterLayout = array_replace_recursive([
        'columns' => 4,
        'show_payment_strip' => true,
        'sections' => [
            'brand' => ['order' => 10, 'visible' => true],
            'links' => ['order' => 20, 'visible' => true],
            'company' => ['order' => 30, 'visible' => true],
            'contact' => ['order' => 40, 'visible' => true],
        ],
    ], $siteFooterLayout);
    $showPayStrip = $siteFooterLayout['show_payment_strip'];
@endphp
<footer class="bg-[#F3F5F8] text-slate-600 border-t border-slate-200/90 pt-14 pb-8 px-4 sm:px-6">
    <div class="max-w-[1200px] mx-auto">
        <div class="flex flex-wrap gap-10 pb-10">
            <!-- Column 1: Brand Overview -->
            <div class="space-y-4" style="order:{{ $siteFooterLayout['sections']['brand']['order'] }};flex:1 1 calc(100% / {{ $siteFooterLayout['columns'] }} - 40px);min-width:220px;display:{{ $siteFooterLayout['sections']['brand']['visible'] ? 'block' : 'none' }}">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5">
                    @if (empty($siteIdentity['logo_url']))
                    <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 shrink-0" aria-hidden="true">
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
                        <img src="{{ $siteIdentity['logo_url'] }}" alt="{{ config('app.name') }} logo" class="h-8 max-w-32 object-contain">
                    @endif
                    <span class="text-base font-extrabold text-slate-900 tracking-tight">{{ config('app.name') }}</span>
                </a>
                <p class="text-xs leading-relaxed text-slate-500">{{ $footerDesc }}</p>
                <div class="flex items-center gap-2.5 pt-1">
                    <a href="{{ $footerYoutube }}" target="_blank" rel="noopener" aria-label="{{ __('YouTube Lessons') }}" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 7.2a2.7 2.7 0 0 0-1.9-1.9C18 4.8 12 4.8 12 4.8s-6 0-7.7.5a2.7 2.7 0 0 0-1.9 1.9C2 8.9 2 12 2 12s0 3.1.4 4.8a2.7 2.7 0 0 0 1.9 1.9c1.7.5 7.7.5 7.7.5s6 0 7.7-.5a2.7 2.7 0 0 0 1.9-1.9c.4-1.7.4-4.8.4-4.8s0-3.1-.4-4.8zM10 15.2V8.8L15.4 12 10 15.2z"/></svg>
                    </a>
                    <a href="{{ route('public.learn') }}" aria-label="{{ __('Free Video Hub') }}" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                    </a>
                    <a href="{{ $footerFacebook }}" target="_blank" rel="noopener" aria-label="{{ __('Facebook Community') }}" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 9H16V6h-2.5C11.6 6 10 7.6 10 9.5V11H8v3h2v7h3v-7h2.3l.7-3H13V9.5c0-.3.2-.5.5-.5z"/></svg>
                    </a>
                    <a href="{{ route('public.contact') }}" aria-label="{{ __('Contact Us') }}" class="w-9 h-9 rounded-full bg-[#E9ECEF] hover:bg-[#0284C7] text-slate-700 hover:text-white flex items-center justify-center transition-colors font-bold text-xs">
                        <span>@</span>
                    </a>
                </div>
            </div>

            <!-- Column 2: Links -->
            <div style="order:{{ $siteFooterLayout['sections']['links']['order'] }};flex:1 1 calc(100% / {{ $siteFooterLayout['columns'] }} - 40px);min-width:220px;display:{{ $siteFooterLayout['sections']['links']['visible'] ? 'block' : 'none' }}">
                <h4 class="text-sm font-bold text-slate-900 mb-4">{{ __('Links') }}</h4>
                <ul class="space-y-2.5 text-xs text-slate-600">
                    @foreach (app(\App\Core\Appearance\SiteNavigationManager::class)->items('footer') as $item)
                        <li>
                            <a href="{{ $item['url'] }}" @if (!empty($item['external'])) target="_blank" rel="noopener noreferrer" @endif class="inline-flex items-center gap-2 hover:text-[#0284C7] transition-colors">
                                <span class="text-slate-400">&rarr;</span><span>{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <!-- Column 3: Company Details -->
            <div style="order:{{ $siteFooterLayout['sections']['company']['order'] }};flex:1 1 calc(100% / {{ $siteFooterLayout['columns'] }} - 40px);min-width:220px;display:{{ $siteFooterLayout['sections']['company']['visible'] ? 'block' : 'none' }}">
                <h4 class="text-sm font-bold text-slate-900 mb-4">{{ __('Company Details') }}</h4>
                <div class="space-y-3 text-xs text-slate-600">
                    <div class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        <span>{{ __('License No:') }} <strong class="font-mono text-slate-800">{{ $footerLicense }}</strong></span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span>{{ $footerAddress }}</span>
                    </div>
                </div>
            </div>

            <!-- Column 4: Contact -->
            <div style="order:{{ $siteFooterLayout['sections']['contact']['order'] }};flex:1 1 calc(100% / {{ $siteFooterLayout['columns'] }} - 40px);min-width:220px;display:{{ $siteFooterLayout['sections']['contact']['visible'] ? 'block' : 'none' }}">
                <h4 class="text-sm font-bold text-slate-900 mb-4">{{ __('Contact') }}</h4>
                <div class="space-y-3 text-xs text-slate-600">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                        <a href="tel:{{ preg_replace('/\s+/', '', $footerPhone) }}" class="font-mono tabular-nums font-semibold text-slate-800 hover:text-[#0284C7]">{{ $footerPhone }}</a>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-[#0284C7] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        <a href="mailto:{{ $footerEmail }}" class="hover:text-[#0284C7]">{{ $footerEmail }}</a>
                    </div>
                </div>
            </div>
        </div>

        @if ($showPayStrip)
        <div class="max-w-4xl mx-auto my-4 bg-white border border-slate-200/80 rounded-xl px-5 py-3.5 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex flex-wrap items-center justify-center gap-1.5">
                <span class="text-xs font-bold text-[#0284C7] mr-2 whitespace-nowrap">{{ __('Pay With') }}</span>
                @foreach ([
                    ['VISA', 'text-[#1A1F71] border-indigo-200 bg-indigo-50/70'],
                    ['Mastercard', 'text-[#EB001B] border-red-200 bg-red-50/70'],
                    ['AMEX', 'text-[#006FCF] border-sky-200 bg-sky-50/70'],
                    ['DBBL Nexus', 'text-[#005B9F] border-blue-200 bg-blue-50/70'],
                    ['bKash', 'text-[#E2136E] border-pink-200 bg-pink-50/70'],
                    ['Nagad', 'text-[#F6921E] border-orange-200 bg-orange-50/70'],
                    ['Rocket', 'text-[#8C3494] border-purple-200 bg-purple-50/70'],
                    ['Upay', 'text-[#0066B3] border-sky-200 bg-sky-50/70'],
                    ['City Bank', 'text-[#B91C1C] border-rose-200 bg-rose-50/70'],
                    ['Islami Bank', 'text-[#047857] border-emerald-200 bg-emerald-50/70'],
                    ['UCB', 'text-[#B91C1C] border-red-200 bg-red-50/70'],
                    ['MTB', 'text-[#0369A1] border-sky-200 bg-sky-50/70'],
                    ['BRAC Bank', 'text-[#0F766E] border-teal-200 bg-teal-50/70'],
                    ['EBL', 'text-[#1D4ED8] border-blue-200 bg-blue-50/70'],
                ] as [$gw, $cls])
                    <span class="px-2 py-0.5 rounded border text-[10px] font-bold tracking-tight whitespace-nowrap {{ $cls }}">{{ $gw }}</span>
                @endforeach
            </div>
            <div class="sm:pl-3 sm:border-l border-slate-200 text-center sm:text-right shrink-0">
                <div class="text-[9px] text-slate-400 leading-none">Verified by</div>
                <div class="text-[11px] font-black text-[#1E3A8A] tracking-tight whitespace-nowrap">SSLCOMMERZ</div>
            </div>
        </div>
        @endif

        <div class="mt-8 pt-5 border-t border-slate-300/70 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
            <div>{{ $siteIdentity['footer_text'] ?: ('© ' . date('Y') . ' ' . config('app.name') . '. All Rights Reserved.') }}</div>
            <div class="flex items-center gap-5">
                <a href="{{ route('public.terms') }}" class="hover:text-slate-900">{{ __('Terms & Conditions') }}</a>
                <a href="{{ route('public.privacy') }}" class="hover:text-slate-900">{{ __('Privacy Policy') }}</a>
                <a href="{{ route('public.refund') }}" class="hover:text-slate-900">{{ __('Refund Policy') }}</a>
            </div>
        </div>
    </div>
</footer>
