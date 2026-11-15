@extends('layouts.app')
@section('title', $product['title'] . " — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col bg-[#F8FAFC]">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 px-4 sm:px-6">
        <div class="max-w-[980px] mx-auto grid grid-cols-1 md:grid-cols-12 gap-8 items-start bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs">
            <div class="md:col-span-5 space-y-3">
                <div class="rounded-2xl overflow-hidden bg-slate-100 aspect-square border border-slate-200">
                    <img src="{{ $product['image'] }}" alt="{{ $product['title'] }}" class="w-full h-full object-cover">
                </div>
                @if (!empty($product['pdf_thumbnail_path']))
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center gap-3">
                        <img src="{{ $product['pdf_thumbnail_path'] }}" alt="Sample PDF Preview" class="w-12 h-16 rounded object-cover border">
                        <div class="text-xs">
                            <div class="font-bold text-slate-800">Sample Pages PDF Preview</div>
                            @if (!empty($product['download_url']))
                                <a href="{{ $product['download_url'] }}" target="_blank" rel="noopener" class="text-[#0284C7] font-semibold hover:underline">Download Sample PDF &rarr;</a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div class="md:col-span-7 space-y-5">
                <div class="flex flex-wrap gap-2">
                    <span class="px-3 py-1 rounded-full bg-sky-50 text-[#0284C7] text-[11px] font-bold uppercase">{{ $product['category'] }}</span>
                    <span class="px-3 py-1 rounded-full bg-amber-100 text-amber-900 text-[11px] font-bold uppercase">{{ $product['badge'] }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900">{{ $product['title'] }}</h1>
                <p class="text-sm text-slate-600 leading-relaxed">{{ $product['summary'] }}</p>

                <div class="pt-4 border-t border-slate-100 flex items-baseline gap-3">
                    <span class="text-3xl font-extrabold font-mono text-[#0284C7]">৳{{ number_format((float) $product['price']) }}</span>
                    @if ((float) $product['original_price'] > (float) $product['price'])
                        <span class="text-sm font-mono text-slate-400 line-through">৳{{ number_format((float) $product['original_price']) }}</span>
                    @endif
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ Auth::check() ? route('admin.dashboard') : route('login') }}" class="px-6 py-3 rounded-xl bg-[#0057FF] hover:bg-[#0046CC] text-white text-sm font-bold shadow-sm transition-colors">
                        {{ Auth::check() ? 'Place Order in Portal' : 'Sign in to Order' }}
                    </a>
                    @if (!empty($product['download_url']))
                        <a href="{{ $product['download_url'] }}" target="_blank" rel="noopener" class="px-5 py-3 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold transition-colors">
                            Download Digital File
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
