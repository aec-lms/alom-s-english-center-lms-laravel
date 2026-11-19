@extends('layouts.app')
@section('title', $post->seo_title ?: $post->title)
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')
    <main class="flex-1 py-10 sm:py-14 px-4 sm:px-6 bg-slate-50">
        <article class="max-w-4xl mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            @if ($post->thumbnail_path || $post->pdf_thumbnail)
                <img src="{{ $post->thumbnail_path ?: $post->pdf_thumbnail }}" alt="{{ $post->title }}" class="w-full max-h-[440px] object-cover">
            @endif
            <div class="p-6 sm:p-10 space-y-5">
                <a href="{{ route('public.learn') }}" class="text-sm font-semibold text-sky-700">&larr; All articles</a>
                <div class="text-xs font-bold uppercase tracking-wide text-sky-700">{{ $post->category ?: 'Article' }}</div>
                <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900">{{ $post->title }}</h1>
                <p class="text-sm text-slate-500">By {{ $post->author ?: "Alom's English Center" }}</p>
                @if ($post->excerpt)
                    <p class="text-lg text-slate-600 leading-relaxed">{{ $post->excerpt }}</p>
                @endif
                <div class="prose max-w-none text-slate-800 leading-8 whitespace-pre-line">{{ $post->content }}</div>
                @if ($post->pdf_path)
                    <a href="{{ $post->pdf_path }}" target="_blank" rel="noopener noreferrer" class="inline-flex px-5 py-3 rounded-lg bg-sky-700 text-white font-bold">Open attached PDF</a>
                @endif
            </div>
        </article>
    </main>
    @include('public.partials.footer')
</div>
@endsection
