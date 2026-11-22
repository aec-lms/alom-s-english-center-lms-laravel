@extends('layouts.app')
@section('title', "{{ __('All E-Books') }} — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-10 sm:py-14 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[1200px] mx-auto space-y-7">
            <div class="text-center space-y-5">
                <div class="inline-flex items-center justify-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#4F46E5] text-white flex items-center justify-center shadow-md">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </span>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1E1B4B] tracking-tight">All E-Books</h1>
                </div>

                <form method="GET" action="{{ route('public.ebooks') }}" class="mx-auto flex max-w-2xl flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:flex-row">
                    <label class="sr-only" for="ebook-search">{{ __('Search e-books') }}</label>
                    <input id="ebook-search" type="search" name="q" value="{{ $searchTerm }}" maxlength="100" placeholder="{{ __('Search by title, subject or keyword') }}" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                    <input type="hidden" name="category" value="{{ $activeCategory }}">
                    <input type="hidden" name="type" value="{{ $priceFilter }}">
                    <button type="submit" class="rounded-xl bg-[#0284C7] px-5 py-3 text-sm font-bold text-white hover:bg-[#0369A1]">{{ __('Search') }}</button>
                </form>

                <!-- Primary Category Filter Pills -->
                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <a
                        href="{{ route('public.ebooks', ['category' => 'all', 'type' => $priceFilter, 'q' => $searchTerm]) }}"
                        class="px-4 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-2 transition-all {{ $activeCategory === 'all' ? 'bg-gradient-to-r from-[#2563EB] to-[#4F46E5] text-white shadow-md' : 'bg-white text-slate-700 border border-slate-200 hover:border-blue-300' }}"
                    >
                        <span>All E-Books</span>
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono {{ $activeCategory === 'all' ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $allBooks->count() }}</span>
                    </a>

                    @foreach ($categories as $cat)
                        @php $isCat = strcasecmp($activeCategory, $cat) === 0; @endphp
                        <a
                            href="{{ route('public.ebooks', ['category' => $cat, 'type' => $priceFilter, 'q' => $searchTerm]) }}"
                            class="px-4 py-1.5 rounded-full text-xs font-semibold inline-flex items-center gap-2 transition-all {{ $isCat ? 'bg-gradient-to-r from-[#2563EB] to-[#4F46E5] text-white shadow-md font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:border-blue-300' }}"
                        >
                            <span>{{ $cat }}</span>
                            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono {{ $isCat ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500' }}">{{ $allBooks->where('category', $cat)->count() }}</span>
                        </a>
                    @endforeach
                </div>

                <!-- Secondary Filter Strip -->
                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <div class="inline-flex items-center gap-1 bg-white/95 border border-slate-200/90 rounded-lg p-1 shadow-2xs">
                        @foreach ([
                            ['all', 'All', $catFiltered->count()],
                            ['free', 'Free', $freeCount],
                            ['premium', 'Premium', $premiumCount],
                        ] as [$key, $label, $cnt])
                            @php $isType = $priceFilter === $key; @endphp
                            <a
                                href="{{ route('public.ebooks', ['category' => $activeCategory, 'type' => $key, 'q' => $searchTerm]) }}"
                                class="px-3 py-1 rounded-md text-[11px] font-bold inline-flex items-center gap-1.5 transition-colors {{ $isType ? 'bg-[#0284C7] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                            >
                                <span>{{ $label }}</span>
                                <span class="text-[10px] font-mono px-1.5 rounded {{ $isType ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500' }}">{{ $cnt }}</span>
                            </a>
                        @endforeach
                    </div>

                    <a href="{{ route('login') }}" class="px-4 py-1.5 rounded-lg text-[11px] font-bold border bg-white text-[#0284C7] border-sky-200 hover:border-[#0284C7] transition-all inline-flex items-center gap-1.5 shadow-2xs">
                        <span>{{ __('My E-Books') }}</span>
                    </a>
                </div>
            </div>

            <!-- 4-Column Portrait E-Book Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 pt-2">
                @forelse ($visibleBooks as $eb)
                    @php
                        $discount = (!$eb['is_free'] && $eb['original_price'] > $eb['price'])
                            ? (int) round((($eb['original_price'] - $eb['price']) / $eb['original_price']) * 100)
                            : 0;
                    @endphp
                    <a href="{{ route('public.ebooks.show', $eb['slug']) }}" class="rounded-2xl bg-white border border-slate-200/80 shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-200 overflow-hidden flex flex-col justify-between p-2.5 group">
                        <div>
                            @php $coverImg = $eb['pdf_thumbnail_path'] ?? ($eb['thumbnail_path'] ?? null); @endphp
                            <div class="relative h-[300px] sm:h-[320px] rounded-xl overflow-hidden shadow-xs bg-gradient-to-br {{ $eb['gradient'] }} p-5 flex flex-col justify-between text-white">
                                @if ($coverImg)
                                    <img src="{{ $coverImg }}" alt="{{ $eb['title'] }}" class="absolute inset-0 w-full h-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/35 to-slate-950/45"></div>
                                @endif
                                <div class="relative z-10 flex items-center justify-between gap-2">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider {{ $eb['is_free'] ? 'bg-[#10B981] text-white' : 'bg-[#F59E0B] text-slate-950' }}">
                                        {{ $eb['is_free'] ? 'FREE' : 'PREMIUM' }}
                                    </span>
                                    @if ($discount > 0)
                                        <span class="px-2 py-0.5 rounded-full bg-[#EF4444] text-white text-[10px] font-extrabold">-{{ $discount }}%</span>
                                    @else
                                        <span class="text-[10px] font-mono text-white/90 font-semibold">{{ $eb['pages'] }}P</span>
                                    @endif
                                </div>

                                <div class="relative z-10 my-auto py-3 space-y-2.5 text-center">
                                    <div class="inline-block px-3 py-1 rounded-full bg-white/15 border border-white/20 text-[10px] font-extrabold uppercase tracking-widest">
                                        {{ $eb['category'] }}
                                    </div>
                                    <h3 class="font-black leading-tight text-white text-lg sm:text-xl drop-shadow">{{ $eb['title'] }}</h3>
                                    <div class="inline-block px-3 py-0.5 rounded-md bg-white text-slate-900 font-extrabold text-[10px] tracking-wider uppercase shadow-sm">
                                        {{ $eb['is_free'] ? '100% Free Edition' : 'ShortNote 2.0 E-Book' }}
                                    </div>
                                </div>

                                <div class="relative z-10 pt-3 border-t border-white/15 flex items-center justify-between text-[10px] text-white/90 font-semibold">
                                    <span>Alom's English Center</span>
                                    <span class="font-mono">{{ $eb['pages'] }} Pages</span>
                                </div>
                            </div>

                            <div class="px-2 pt-3.5 pb-1 space-y-1">
                                <h2 class="text-sm font-extrabold text-slate-900 line-clamp-2 group-hover:text-[#0284C7] transition-colors leading-snug">
                                    {{ $eb['title'] }}
                                </h2>
                                <p class="text-[11px] text-slate-500 line-clamp-2 leading-relaxed">
                                    {{ $eb['summary'] }}
                                </p>
                            </div>
                        </div>

                        <div class="px-2 pt-3 pb-1.5 mt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                            <div>
                                @if ($eb['is_free'])
                                    <span class="text-sm font-extrabold text-[#16A34A]">{{ __('Free') }}</span>
                                @else
                                    <div class="flex flex-col leading-none">
                                        <span class="text-sm font-extrabold font-mono text-[#0284C7]">৳{{ $eb['price'] }}</span>
                                        @if ($eb['original_price'] > $eb['price'])
                                            <span class="text-[10px] font-mono text-slate-400 line-through mt-0.5">৳{{ $eb['original_price'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <span class="inline-flex items-center gap-1 px-3.5 py-1.5 rounded-full bg-[#EFF6FF] group-hover:bg-[#0284C7] text-[#0284C7] group-hover:text-white text-[11px] font-bold transition-colors">
                                <span>{{ $eb['is_free'] ? 'Get free' : 'View' }}</span>
                                <span>&rarr;</span>
                            </span>
                        </div>
                    </a>
                @empty
                    <section class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                        <h2 class="text-lg font-bold text-slate-800">{{ __('No e-books found') }}</h2>
                        <p class="mt-2 text-sm text-slate-500">{{ __('Try another search term, category or price filter.') }}</p>
                    </section>
                @endforelse
            </div>
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
