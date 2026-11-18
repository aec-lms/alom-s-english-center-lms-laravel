@extends('layouts.app')

@section('title', __('My Wishlist') . ' | ' . config('app.name'))

@section('content')
@include('public.partials.navbar')
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="inline-flex rounded-full bg-sky-50 text-sky-700 px-3 py-1 text-xs font-semibold">{{ __('My learning') }}</span>
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mt-3">{{ __('My Wishlist') }}</h1>
            <p class="text-sm text-slate-500 mt-1">{{ __('Courses you saved to explore later.') }}</p>
        </div>
        <a class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="{{ url('/courses') }}">{{ __('Browse courses') }}</a>
    </div>

    @if(session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    @if($courses->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 sm:p-12 text-center shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Your wishlist is empty') }}</h2>
            <p class="text-sm text-slate-500 mt-2">{{ __('Save published courses and they will appear here.') }}</p>
            <a class="inline-flex mt-5 rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-sky-700" href="{{ url('/courses') }}">{{ __('Explore courses') }}</a>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
            @foreach($courses as $course)
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm flex flex-col">
                    @if($course->thumbnail)
                        <img src="{{ $course->thumbnail }}"
                             alt="{{ $course->title }}" loading="lazy"
                             class="w-full aspect-video object-cover">
                    @endif
                    <div class="p-5 flex flex-col flex-1">
                        <h2 class="text-lg font-semibold text-slate-900">{{ $course->title }}</h2>
                        <p class="text-sm text-slate-500 mt-2">{{ \Illuminate\Support\Str::limit(strip_tags((string) $course->description), 130) }}</p>
                        <p class="font-bold text-slate-900 mt-auto pt-4">{{ __('BDT') }} {{ number_format((float) $course->price, 2) }}</p>
                        <div class="flex items-center gap-2 mt-4">
                            <a class="inline-flex rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700" href="{{ url('/course/' . $course->slug) }}">{{ __('View course') }}</a>
                            <form method="POST" action="{{ route('plugins.course-wishlist.destroy', $course->id) }}">
                                @csrf
                                @method('DELETE')
                                <button class="inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" type="submit">{{ __('Remove') }}</button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="pt-2">{{ $courses->links() }}</div>
    @endif
</div>
@endsection
