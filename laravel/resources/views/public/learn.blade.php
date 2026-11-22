@extends('layouts.app')
@section('title', "Free Learning Hub — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[1160px] mx-auto space-y-10">
            <div class="text-center max-w-2xl mx-auto space-y-2.5">
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-[#0084D4]">
                    Free Video Lessons &amp; Study Hub
                </h1>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                    Watch open concept lessons from our lead instructors before joining a live batch.
                </p>
            </div>

            <form method="GET" action="{{ route('public.learn') }}" class="mx-auto flex max-w-2xl flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:flex-row">
                <label class="sr-only" for="learning-search">{{ __('Search learning resources') }}</label>
                <input id="learning-search" type="search" name="q" value="{{ $searchTerm }}" maxlength="100" placeholder="{{ __('Search lessons, topics or instructors') }}" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                <button type="submit" class="rounded-xl bg-[#0284C7] px-5 py-3 text-sm font-bold text-white hover:bg-[#0369A1]">{{ __('Search') }}</button>
            </form>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-6">
                @forelse ($lessons as $lesson)
                    <article id="{{ $lesson['slug'] }}" class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-xs hover:shadow-xl transition-all duration-200 flex flex-col sm:flex-row">
                        <div class="sm:w-52 h-44 sm:h-auto relative bg-[#022D41] shrink-0 overflow-hidden">
                            <img src="{{ $lesson['thumbnail'] }}" alt="{{ $lesson['title'] }}" class="w-full h-full object-cover">
                            <span class="absolute bottom-2.5 right-2.5 px-2 py-0.5 rounded bg-slate-950/80 text-white text-[10px] font-mono font-bold">
                                {{ $lesson['duration'] }}
                            </span>
                        </div>
                        <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                            <div class="space-y-2">
                                <span class="inline-block px-2.5 py-0.5 rounded-full bg-[#E6F4FE] text-[#007CC7] text-[10px] font-bold uppercase tracking-wider">
                                    {{ $lesson['category'] }}
                                </span>
                                <h2 class="text-base font-extrabold text-slate-900 leading-snug">{{ $lesson['title'] }}</h2>
                                <p class="text-xs text-slate-500">Instructor: <strong class="text-slate-700">{{ $lesson['instructor'] }}</strong></p>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                                <span class="text-[11px] font-semibold text-emerald-600">Free Open Access</span>
                                <a href="{{ route('public.learn.show', $lesson['slug']) }}" class="px-3.5 py-1.5 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white text-xs font-bold transition-colors">
                                    Read Article
                                </a>
                            </div>
                        </div>
                    </article>
                @empty
                    <section class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                        <h2 class="text-lg font-bold text-slate-800">{{ __('No learning resources found') }}</h2>
                        <p class="mt-2 text-sm text-slate-500">{{ __('Try another topic, lesson title or instructor name.') }}</p>
                    </section>
                @endforelse
            </div>
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
