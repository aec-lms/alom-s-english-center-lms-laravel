@extends('layouts.app')
@section('title', ($page->seo_title ?: $page->title) . " — Alom's English Center")
@section('meta_description', $page->seo_description ?: '')
@php
    $articleClasses = match ($page->template ?? 'standard') {
        'landing' => 'max-w-[1120px] mx-auto bg-white rounded-3xl border border-white/90 shadow-xl overflow-hidden',
        'fullwidth' => 'w-full max-w-none mx-auto bg-white rounded-none border-0 shadow-none overflow-hidden',
        default => 'max-w-[920px] mx-auto bg-white rounded-2xl border border-white/90 shadow-lg overflow-hidden',
    };
@endphp
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background:linear-gradient(135deg,#EAE9FF 0%,#F2F3FF 45%,#E5F4FE 100%)">
        <article class="{{ $articleClasses }}">
            @if (!empty($page->banner_image))
                <div class="h-56 sm:h-72 w-full bg-slate-900 overflow-hidden">
                    <img src="{{ $page->banner_image }}" alt="{{ $page->title }}" class="w-full h-full object-cover">
                </div>
            @endif

            <div class="p-6 sm:p-10 space-y-6">
                <div class="text-xs font-semibold text-slate-400">
                    <a href="{{ route('home') }}" class="hover:text-[#0284C7]">Home</a> &rsaquo;
                    <span class="text-slate-700">{{ $page->title }}</span>
                </div>

                <h1 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ $page->title }}</h1>

                @foreach ($blocks as $block)
                    @if ($block['type'] === 'heading')
                        <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900" style="text-align:{{ $block['align'] }}">{{ $block['content'] }}</h2>
                    @elseif ($block['type'] === 'text')
                        <p class="text-sm sm:text-base leading-8 text-slate-600 whitespace-pre-line" style="text-align:{{ $block['align'] }}">{{ $block['content'] }}</p>
                    @elseif ($block['type'] === 'image')
                        <figure class="my-5">
                            <img src="{{ $block['url'] }}" alt="{{ $block['alt'] }}" loading="lazy" class="mx-auto max-h-[640px] w-full rounded-2xl object-contain">
                            @if ($block['alt'] !== '')<figcaption class="mt-2 text-center text-xs text-slate-500">{{ $block['alt'] }}</figcaption>@endif
                        </figure>
                    @elseif ($block['type'] === 'button')
                        <div class="flex" style="justify-content:{{ $block['align'] === 'center' ? 'center' : ($block['align'] === 'right' ? 'flex-end' : 'flex-start') }}">
                            <a href="{{ $block['url'] }}" @if (str_starts_with($block['url'], 'http://') || str_starts_with($block['url'], 'https://')) target="_blank" rel="noopener noreferrer" @endif class="inline-flex items-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-bold text-white hover:bg-sky-700">{{ $block['label'] }}</a>
                        </div>
                    @elseif ($block['type'] === 'spacer')
                        <div aria-hidden="true" style="height:{{ $block['height'] }}px"></div>
                    @elseif ($block['type'] === 'course_grid')
                        <section class="space-y-4">
                            <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Explore our courses</h2>
                            @if ($courseCards->isEmpty())
                                <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">Published courses will appear here automatically.</p>
                            @else
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($courseCards as $course)
                                        <article class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                            <span class="text-[11px] font-bold uppercase tracking-wide text-sky-700">{{ $course->category ?? 'English Course' }}</span>
                                            <h3 class="mt-2 text-base font-extrabold text-slate-900">{{ $course->title }}</h3>
                                            <p class="mt-2 flex-1 text-xs leading-6 text-slate-500">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($course->description ?? '')), 120) }}</p>
                                            <div class="mt-4 flex items-center justify-between gap-2">
                                                <span class="text-sm font-bold text-slate-900">{{ number_format((float) ($course->price ?? 0), 2) }}</span>
                                                <a href="{{ route('public.courses.show', $course->slug) }}" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-bold text-white">View course</a>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @elseif ($block['type'] === 'instructor_grid')
                        <section class="space-y-4">
                            <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Meet our instructors</h2>
                            @if (empty($instructorCards))
                                <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">Active instructors will appear here automatically.</p>
                            @else
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($instructorCards as $instructor)
                                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                            <h3 class="font-extrabold text-slate-900">{{ $instructor['name'] }}</h3>
                                            <p class="mt-1 text-xs font-semibold text-sky-700">{{ $instructor['subject'] }}</p>
                                            <p class="mt-2 text-xs leading-6 text-slate-500">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($instructor['role'] ?? '')), 100) }}</p>
                                            <a href="{{ route('public.instructors.show', $instructor['id']) }}" class="mt-4 inline-flex text-xs font-bold text-sky-700">View instructor →</a>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endif
                @endforeach
            </div>
        </article>
    </main>

    @include('public.partials.footer')
</div>
@endsection
