@extends('layouts.app')
@section('title', "Study Kits & Products — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[1160px] mx-auto space-y-10">
            <div class="text-center max-w-2xl mx-auto space-y-2.5">
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-[#0084D4]">
                    Printed Books &amp; Study Kits
                </h1>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                    Official printed handbooks, flashcards, and classroom kits delivered nationwide.
                </p>
            </div>

            <form method="GET" action="{{ route('public.products') }}" class="max-w-2xl mx-auto flex gap-2 rounded-2xl bg-white p-3 shadow-sm border border-slate-200">
                <label class="sr-only" for="product-search">{{ __('Search products') }}</label>
                <input id="product-search" type="search" name="q" value="{{ $searchTerm }}" maxlength="100" placeholder="{{ __('Search books, study kits or categories') }}" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                <button type="submit" class="rounded-xl bg-[#0077CC] px-5 py-3 text-sm font-bold text-white hover:bg-[#0060A8]">{{ __('Search') }}</button>
            </form>

            @if ($products->isEmpty())
                <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold text-slate-800">{{ __('No products found') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Try another product name or category.') }}</p>
                </section>
            @else
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach ($products as $prod)
                    <article class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-200 flex flex-col justify-between">
                        <div>
                            <a href="{{ route('public.products.show', $prod['slug']) }}" class="relative h-56 bg-slate-100 overflow-hidden block">
                                <img src="{{ $prod['image'] }}" alt="{{ $prod['title'] }}" class="w-full h-full object-cover">
                                <span class="absolute top-3 left-3 px-2.5 py-0.5 rounded-full bg-[#0284C7] text-white text-[10px] font-bold uppercase tracking-wider">
                                    {{ $prod['category'] }}
                                </span>
                                <span class="absolute top-3 right-3 px-2.5 py-0.5 rounded-full bg-amber-400 text-slate-950 text-[10px] font-extrabold uppercase">
                                    {{ $prod['badge'] }}
                                </span>
                            </a>

                            <div class="p-5 space-y-2">
                                <a href="{{ route('public.products.show', $prod['slug']) }}" class="text-base font-extrabold text-slate-900 hover:text-[#0284C7] leading-snug block">{{ $prod['title'] }}</a>
                                <p class="text-xs text-slate-500 leading-relaxed">{{ $prod['summary'] }}</p>
                            </div>
                        </div>

                        <div class="px-5 py-4 border-t border-slate-100 flex items-center justify-between gap-2">
                            <div>
                                <div class="text-lg font-extrabold font-mono text-[#0284C7]">৳{{ number_format((float) $prod['price']) }}</div>
                                @if ((float) $prod['original_price'] > (float) $prod['price'])
                                    <div class="text-[11px] font-mono text-slate-400 line-through">৳{{ number_format((float) $prod['original_price']) }}</div>
                                @endif
                            </div>

                            <a href="{{ route('public.products.show', $prod['slug']) }}" class="px-4 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white text-xs font-bold transition-colors">
                                View Product
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
            @endif
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
