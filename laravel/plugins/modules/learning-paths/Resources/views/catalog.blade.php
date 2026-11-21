@extends('layouts.app')

@section('title', '{{ __('Learning Paths') }}')

@section('content')
<div class="mx-auto max-w-6xl space-y-6 px-4 py-8">
    <header><p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">{{ __('Learn step by step') }}</p><h1 class="mt-1 text-3xl font-extrabold text-slate-900">{{ __('Learning Paths') }}</h1><p class="mt-2 text-slate-600">{{ __('Structured course journeys that unlock one step at a time.') }}</p></header>
    @if (session('status'))<div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($paths as $path)
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-xl font-bold">{{ $path->title }}</h2><p class="mt-2 text-sm text-slate-600">{{ $path->description }}</p>
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $path->courses->count() }} {{ __('courses') }} · {{ $path->enrolled ? @json(__('Joined')) : @json(__('Open path')) }}</p>
                <ol class="mt-3 space-y-2">@foreach ($path->courses as $course)<li class="text-sm text-slate-700">{{ $course->position }}. {{ $course->course_title }}</li>@endforeach</ol>
                <a href="{{ route('learning-paths.show', $path->slug) }}" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{{ __('View learning path') }}</a>
            </article>
        @empty
            <p class="rounded-xl border border-dashed p-8 text-sm text-slate-500">{{ __('No published learning paths are available for this branch yet.') }}</p>
        @endforelse
    </div>
    {{ $paths->links() }}
</div>
@endsection
