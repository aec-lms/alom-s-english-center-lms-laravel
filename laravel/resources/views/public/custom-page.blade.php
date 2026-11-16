@extends('layouts.app')
@section('title', ($page->seo_title ?: $page->title) . " — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <article class="max-w-[920px] mx-auto bg-white rounded-2xl border border-white/90 shadow-lg overflow-hidden">
            @if (!empty($page->banner_image))
                <div class="h-56 sm:h-72 w-full bg-slate-900 overflow-hidden">
                    <img src="{{ $page->banner_image }}" alt="{{ $page->title }}" class="w-full h-full object-cover">
                </div>
            @endif

            <div class="p-6 sm:p-10 space-y-5">
                <div class="text-xs font-semibold text-slate-400">
                    <a href="{{ route('home') }}" class="hover:text-[#0284C7]">Home</a> &rsaquo;
                    <span class="text-slate-700">{{ $page->title }}</span>
                </div>

                <h1 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
                    {{ $page->title }}
                </h1>

                <div class="prose max-w-none text-sm text-slate-600 leading-relaxed whitespace-pre-line">
                    {{ $body }}
                </div>

                @if ($cta)
                    <div class="pt-3">
                        <a href="{{ $cta['url'] }}" @if (str_starts_with($cta['url'], 'http://') || str_starts_with($cta['url'], 'https://')) target="_blank" rel="noopener noreferrer" @endif class="inline-flex items-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-bold text-white hover:bg-sky-700">
                            {{ $cta['label'] }}
                        </a>
                    </div>
                @endif
            </div>
        </article>
    </main>

    @include('public.partials.footer')
</div>
@endsection
