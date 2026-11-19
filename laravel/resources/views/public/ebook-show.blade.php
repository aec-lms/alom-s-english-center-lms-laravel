@extends('layouts.app')
@section('title', $ebook['title'] . " — Alom's English Center")
@section('content')
@php
    $discount = (!$ebook['is_free'] && $ebook['original_price'] > $ebook['price'])
        ? (int) round((($ebook['original_price'] - $ebook['price']) / $ebook['original_price']) * 100)
        : 0;
@endphp
<div class="min-h-screen flex flex-col bg-[#F8FAFC]">
    @include('public.partials.navbar')

    <!-- Breadcrumb Strip -->
    <div class="w-full bg-white border-b border-slate-100">
        <div class="max-w-[1160px] mx-auto px-4 sm:px-6 py-3 flex items-center gap-2 text-xs text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-[#0284C7] transition-colors">Home</a>
            <span class="text-slate-400">&rsaquo;</span>
            <a href="{{ route('public.ebooks') }}" class="hover:text-[#0284C7] transition-colors">E-books</a>
            <span class="text-slate-400">&rsaquo;</span>
            <span class="font-semibold text-slate-800 truncate">{{ $ebook['title'] }}</span>
        </div>
    </div>

    <!-- Hero Split Showcase Banner -->
    <section class="w-full py-10 sm:py-14 px-4 sm:px-6 border-b border-slate-200/70" style="background: linear-gradient(135deg, #EEF6FF 0%, #FFFFFF 52%, #E5F4FE 100%)">
        <div class="max-w-[980px] mx-auto grid grid-cols-1 md:grid-cols-12 gap-8 sm:gap-12 items-center">
            @php $coverImg = $ebook['pdf_thumbnail_path'] ?? ($ebook['thumbnail_path'] ?? null); @endphp
            <!-- Left Tilted Book Cover -->
            <div class="md:col-span-5 flex justify-center">
                <div class="relative w-[260px] sm:w-[295px] aspect-[3/4] rounded-2xl overflow-hidden shadow-[0_28px_60px_-15px_rgba(15,23,42,0.32)] border border-white/80 transform -rotate-1 hover:rotate-0 transition-transform duration-300 bg-gradient-to-br {{ $ebook['gradient'] }} p-6 flex flex-col justify-between text-white">
                    @if ($coverImg)
                        <img src="{{ $coverImg }}" alt="{{ $ebook['title'] }}" class="absolute inset-0 w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/35 to-slate-950/45"></div>
                    @endif
                    <span class="relative z-10 text-[10px] font-bold tracking-widest uppercase text-white/90">ALOM'S ENGLISH CENTER</span>

                    <div class="relative z-10 my-auto space-y-3 text-center">
                        <div class="inline-block px-3 py-1 rounded-full bg-white/15 border border-white/20 text-[10px] font-extrabold uppercase tracking-widest">
                            {{ $ebook['category'] }}
                        </div>
                        <h2 class="text-xl sm:text-2xl font-black leading-tight text-white drop-shadow">{{ $ebook['title'] }}</h2>
                        <div class="inline-block px-3 py-0.5 rounded-md bg-white text-slate-900 font-extrabold text-[10px] tracking-wider uppercase shadow-sm">
                            {{ $ebook['is_free'] ? '100% Free Edition' : 'ShortNote 2.0 E-Book' }}
                        </div>
                    </div>

                    <div class="relative z-10 pt-3 border-t border-white/15 flex items-center justify-between text-[10px] text-white/90 font-semibold">
                        <span>Verified PDF</span>
                        <span class="font-mono">{{ $ebook['pages'] }} Pages</span>
                    </div>
                </div>
            </div>

            <!-- Right Details & CTA -->
            <div class="md:col-span-7 space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2.5 py-1 rounded-md bg-[#E0F2FE] text-[#0284C7] text-[10px] font-extrabold uppercase tracking-wider">
                        {{ $ebook['is_free'] ? 'FREE E-BOOK' : 'PREMIUM E-BOOK' }}
                    </span>
                    @if ($ebook['is_free'])
                        <span class="px-2.5 py-1 rounded-full bg-[#DCFCE7] text-[#15803D] text-[10px] font-extrabold uppercase tracking-wider">FREE</span>
                    @elseif ($discount > 0)
                        <span class="px-2.5 py-1 rounded-full bg-[#FEE2E2] text-[#B91C1C] text-[10px] font-extrabold uppercase tracking-wider">-{{ $discount }}% OFF</span>
                    @endif
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight leading-tight">
                    {{ $ebook['title'] }}
                </h1>

                <!-- 4 Mini Spec Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                    @foreach ([
                        ['FORMAT', 'PDF · Download'],
                        ['ACCESS', 'Lifetime'],
                        ['READ', 'Any device'],
                        ['SECURE', 'Protected PDF'],
                    ] as [$k, $v])
                        <div class="bg-white/90 border border-slate-200/80 rounded-xl p-3 space-y-1 shadow-2xs">
                            <div class="text-[9px] font-bold uppercase tracking-wider text-slate-400">{{ $k }}</div>
                            <div class="text-[11px] font-bold text-slate-800">{{ $v }}</div>
                        </div>
                    @endforeach
                </div>

                <!-- Elevated White Price & Action Card -->
                <div class="rounded-2xl bg-white border border-slate-200/90 shadow-lg p-5 space-y-4">
                    <div class="space-y-0.5">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">PRICE</div>
                        @if ($ebook['is_free'])
                            <div class="text-3xl font-extrabold text-[#0077CC] tracking-tight">Free</div>
                        @else
                            <div class="flex items-baseline gap-2.5">
                                <span class="text-3xl font-extrabold font-mono text-[#0077CC] tracking-tight">৳{{ $ebook['price'] }}</span>
                                @if ($ebook['original_price'] > $ebook['price'])
                                    <span class="text-sm font-mono text-slate-400 line-through">৳{{ $ebook['original_price'] }}</span>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if (($hasAccess ?? false) && !empty($ebook['download_url']))
                        <a href="{{ route('public.ebooks.download', $ebook['slug']) }}" class="w-full py-3.5 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold flex items-center justify-center gap-2 shadow-sm transition-colors">
                            <span>{{ $ebook['is_free'] ? 'Download Free PDF Now' : 'Download Purchased PDF' }}</span>
                        </a>
                    @elseif ($ebook['is_free'])
                        <p class="text-sm text-slate-500">The free PDF has not been uploaded yet.</p>
                    @elseif (Auth::check() && !($canPurchase ?? false))
                        <p class="text-sm text-amber-700">A protected PDF must be uploaded before this e-book can be sold.</p>
                    @elseif (Auth::check() && Route::has('plugins.commerce_cart.add'))
                        <form method="POST" action="{{ route('plugins.commerce_cart.add') }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="item_type" value="ebook">
                            <input type="hidden" name="item_id" value="{{ $ebook['id'] }}">
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                                <strong>Unified Secure Checkout</strong>
                                <p class="mt-1 text-xs">Add this eBook to your cart and pay together with courses, mock tests and other learning services.</p>
                            </div>
                            <button type="submit" class="w-full py-3.5 px-5 rounded-xl bg-[#0077CC] hover:bg-[#0060A8] text-white text-sm font-bold">Add to Cart · ৳{{ number_format($ebook['price'], 2) }}</button>
                        </form>
                    @elseif (Auth::check())
                        <p class="text-sm text-amber-700">Universal Payment plugin must be enabled to purchase this e-book.</p>
                    @else
                        <a href="{{ route('login') }}" class="w-full py-3.5 px-5 rounded-xl bg-[#0077CC] hover:bg-[#0060A8] text-white text-sm font-bold flex items-center justify-center gap-2 shadow-sm transition-colors">
                            <span>Sign in to purchase</span>
                        </a>
                    @endif

                    <p class="text-center text-[11px] text-slate-400">
                        {{ $ebook['is_free'] ? 'Free — one-time verification, then instant download' : $ebook['pages'] . ' Pages · Instant protected PDF access after verification' }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Bottom About Card -->
    <section class="flex-1 py-10 px-4 sm:px-6">
        <div class="max-w-[980px] mx-auto">
            <div class="rounded-2xl bg-white border border-slate-200/80 shadow-xs p-6 sm:p-8 space-y-5">
                <h2 class="text-base sm:text-lg font-extrabold text-slate-900 border-l-4 border-[#0077CC] pl-3">
                    About this ebook
                </h2>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">{{ $ebook['summary'] }}</p>
                @if (!empty($ebook['topics']))
                    <ul class="space-y-1.5 text-xs sm:text-sm text-slate-600 pt-1">
                        @foreach ($ebook['topics'] as $topic)
                            <li class="flex items-start gap-2">
                                <span class="text-slate-400 font-bold">-</span>
                                <span>{{ $topic }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>

    @include('public.partials.footer')
</div>
@endsection
